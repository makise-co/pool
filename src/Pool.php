<?php

declare(strict_types=1);

namespace MakiseCo\Pool;

use Closure;
use InvalidArgumentException;
use MakiseCo\Connection\ConnectionConfigInterface;
use MakiseCo\Connection\ConnectionInterface;
use MakiseCo\Connection\ConnectorInterface;
use MakiseCo\EvPrimitives\Lock;
use MakiseCo\EvPrimitives\Timer;
use MakiseCo\Pool\Exception\BorrowTimeoutException;
use MakiseCo\Pool\Exception\PoolIsClosedException;
use SplObjectStorage;
use Swoole\Coroutine;
use Throwable;

use function microtime;
use function time;

use const SWOOLE_CHANNEL_OK;
use const SWOOLE_CHANNEL_TIMEOUT;

abstract class Pool implements PoolInterface
{
    /**
     * Connection accepted by pool
     */
    public const PUSH_OK = 0;

    /**
     * Connection discarded by pool, because pool is not initialized
     */
    public const PUSH_POOL_NOT_INITIALIZED = 1;

    /**
     * Connection discarded by pool, because passed connection is not part of pool
     */
    public const PUSH_CONN_NOT_PART_OF_POOL = 2;

    /**
     * Connection discarded by pool, because maximum connection limit has reached
     */
    public const PUSH_LIMIT_REACHED = 3;

    /**
     * Connection discarded by pool, because connection is dead
     */
    public const PUSH_DEAD_CONNECTION = 4;

    /**
     * Connection discarded by pool, because pool was closed
     */
    public const PUSH_POOL_CLOSED = 5;

    /**
     * Connection discarded by pool, because connection max life time has reached
     */
    public const PUSH_MAX_LIFE_TIME = 6;

    private ConnectionConfigInterface $connectionConfig;
    private ConnectorInterface $connector;

    /**
     * Indicates connection pool is ready
     */
    private bool $isInitialized = false;

    /**
     * The maximum number of active connections that can be allocated from this pool at the same time
     */
    private int $maxActive = 2;

    /**
     * The minimum number of established connections that should be kept in the pool at all times.
     * The connection pool can shrink below this number if validation queries fail
     *
     * Idle connections are checked periodically (if enabled)
     * and connections that been idle for longer than maxIdleTime will be released.
     */
    private int $minActive = 0;

    /**
     * The maximum number of seconds that the pool will wait (when there are no available connections)
     * for a connection to be returned before throwing an exception
     */
    private float $maxWaitTime = 5.0;

    /**
     * The number of milliseconds to sleep between runs of the idle connection validation/cleaner timer.
     * This value should not be set under 1 second.
     * It dictates how often we check for idle, abandoned connections, and how often we validate idle connections
     */
    private float $validationInterval = 5.0;

    /**
     * The minimum amount of time (seconds) a connection may sit idle in the pool before it is eligible for closing
     */
    private int $maxIdleTime = 60;

    /**
     * The maximum amount of time (seconds) a connection may exist in the pool before it is eligible for closing
     */
    private int $maxLifeTime = 0;

    /**
     * The indication of whether objects will be validated before being borrowed from the pool.
     * If the object fails to validate, it will be dropped from the pool, and we will attempt to borrow another.
     */
    private bool $testOnBorrow = true;

    /**
     * The indication of whether objects will be validated before being returned to the pool
     */
    private bool $testOnReturn = true;

    /**
     * Reset the connection to its initial state when it is borrowed from the pool
     */
    private bool $resetConnections = false;

    /**
     * Created connections storage
     *
     * @var SplObjectStorage<ConnectionInterface, int>|ConnectionInterface[]
     */
    private SplObjectStorage $connections;

    /**
     * Idle connections queue
     *
     * @var Coroutine\Channel<ConnectionInterface>
     */
    private Coroutine\Channel $idle;

    /**
     * Preventing simultaneous connection creation
     */
    private Lock $lock;

    private Timer $validationTimer;

    /**
     * Total time waited for available connections
     */
    private float $waitDuration = 0.0;

    /**
     * Total number of connections waited for
     */
    private int $waitCount = 0;

    /**
     * Total number of connections closed due to idle time
     */
    private int $maxIdleTimeClosedCount = 0;

    /**
     * Total number of connections closed due to max connection lifetime limit
     */
    private int $maxLifeTimeClosedCount = 0;

    abstract protected function createDefaultConnector(): ConnectorInterface;

    /**
     * Reset connection to its initial state
     *
     * @param ConnectionInterface $connection
     */
    protected function resetConnection(ConnectionInterface $connection): void
    {
    }

    public function __construct(
        ConnectionConfigInterface $connConfig,
        ?ConnectorInterface $connector = null
    ) {
        $this->connectionConfig = $connConfig;
        $this->connector = $connector ?? $this->createDefaultConnector();

        $this->connections = new SplObjectStorage();

        $this->validationTimer = new Timer(
            (int)($this->validationInterval * 1000),
            Closure::fromCallable([$this, 'validateConnections']),
            false
        );
    }

    public function __destruct()
    {
        $this->close();
    }

    public function init(): void
    {
        if ($this->isInitialized) {
            return;
        }

        $this->isInitialized = true;

        $this->idle = new Coroutine\Channel($this->maxActive);
        $this->lock = new Lock();

        // create initial connections
        if ($this->minActive > 0) {
            Coroutine::create(Closure::fromCallable([$this, 'fillPool']));
        }

        $this->startValidationTimer();
    }

    public function close(): void
    {
        if (!$this->isInitialized) {
            return;
        }

        $this->isInitialized = false;

        $this->stopValidationTimer();

        // forget all connection instances
        foreach ($this->connections as $connection) {
            $this->connections->detach($connection);
        }

        Coroutine::create(function () {
            // close all idle connections
            while (!$this->idle->isEmpty()) {
                $connection = $this->idle->pop();
                if (false === $connection) {
                    // connection pool is closed
                    break;
                }

                $this->removeConnection($connection);
            }

            // close idle channel
            $this->idle->close();
        });
    }

    public function isAlive(): bool
    {
        return $this->isInitialized;
    }

    public function getLastUsedAt(): int
    {
        $time = 0;

        foreach ($this->connections as $connection) {
            if (($lastUsedAt = $connection->getLastUsedAt()) > $time) {
                $time = $lastUsedAt;
            }
        }

        return $time;
    }

    /**
     * Set the maximum number of active connections that can be allocated from this pool at the same time
     *
     * @param int $maxActive
     *
     * @throws InvalidArgumentException when maxActive is less than 1
     */
    public function setMaxActive(int $maxActive): void
    {
        // minimal maxActive allowed is 1
        if ($maxActive < 1) {
            throw new InvalidArgumentException('maxActive should be at least 1');
        }

        $oldMaxActive = $this->maxActive;
        $this->maxActive = $maxActive;

        // minActive cannot be greater than maxActive
        if ($this->minActive > $this->maxActive) {
            $this->minActive = $this->maxActive;
        }

        // resize connection pool
        if ($this->isInitialized && $oldMaxActive !== $maxActive) {
            $oldIdle = $this->idle;
            $this->idle = new Coroutine\Channel($maxActive);

            while (!$oldIdle->isEmpty()) {
                /** @var ConnectionInterface|false $idleConnection */
                $idleConnection = $oldIdle->pop();
                if (false === $idleConnection) {
                    // connection pool is closed
                    break;
                }

                if ($this->idle->isFull()) {
                    $this->removeConnection($idleConnection);

                    continue;
                }

                if (!$this->pushConnectionToIdle($idleConnection)) {
                    // connection pool is closed
                    continue;
                }
            }

            $oldIdle->close();
        }
    }

    /**
     * Set the minimum number of established connections that should be kept in the pool at all times.
     * The connection pool can shrink below this number if validation queries fail.
     *
     * Idle connections are checked periodically (if enabled)
     * and connections that been idle for longer than maxIdleTime will be released.
     *
     * @param int $minActive
     */
    public function setMinActive(int $minActive): void
    {
        // minActive cannot be greater than maxActive
        if ($minActive > $this->maxActive) {
            $minActive = $this->maxActive;
        }

        $this->minActive = $minActive;
    }

    /**
     * Set the maximum number of seconds that the pool will wait (when there are no available connections)
     * for a connection to be returned before throwing an exception.
     *
     * @param float $maxWaitTime seconds with milliseconds precision
     *
     * @throws InvalidArgumentException when $maxWaitTime is less than 0
     */
    public function setMaxWaitTime(float $maxWaitTime): void
    {
        // max wait time cannot be negative
        if ($maxWaitTime < 0) {
            throw new InvalidArgumentException('maxWaitTime should be a positive value');
        }

        $this->maxWaitTime = $maxWaitTime;
    }

    /**
     * The minimum amount of time a connection may sit idle in the pool before it is eligible for closing.
     * Zero value is disabling idle checking
     *
     * @param int $maxIdleTime seconds
     *
     * @throws InvalidArgumentException when $maxWaitTime is less than 0
     */
    public function setMaxIdleTime(int $maxIdleTime): void
    {
        // maxIdleTime cannot be negative
        if ($maxIdleTime < 0) {
            throw new InvalidArgumentException('maxIdleTime should be a positive value');
        }

        $this->maxIdleTime = $maxIdleTime;
    }

    /**
     * The maximum amount of time a connection may exist in the pool before it is eligible for closing.
     * Zero value is disabling max life time checking
     *
     * @param int $maxLifeTime seconds
     *
     * @throws InvalidArgumentException when $maxLifeTime is less than 0
     */
    public function setMaxLifeTime(int $maxLifeTime): void
    {
        // maxIdleTime cannot be negative
        if ($maxLifeTime < 0) {
            throw new InvalidArgumentException('maxLifeTime should be a positive value');
        }

        $this->maxLifeTime = $maxLifeTime;
    }

    /**
     * Set the number of seconds to sleep between runs of the idle connection validation/cleaner timer.
     * This value should not be set under 1 second.
     * It dictates how often we check for idle, abandoned connections, and how often we validate idle connections.
     *
     * Zero value will disable connections checking.
     *
     * @param float $validationInterval seconds with milliseconds precision
     *
     * @throws InvalidArgumentException when $idleCheckInterval is less than 0
     */
    public function setValidationInterval(float $validationInterval): void
    {
        if ($validationInterval < 0) {
            throw new InvalidArgumentException('validateConnectionsInterval should be a positive value');
        }

        $this->validationInterval = $validationInterval;

        if ($validationInterval === 0.0) {
            // stop timer on zero idle check interval
            if ($this->validationTimer->isStarted()) {
                $this->validationTimer->stop();
            }
        } else {
            $this->validationTimer->setInterval((int)($validationInterval * 1000));
        }
    }

    /**
     * The indication of whether objects will be validated before being borrowed from the pool.
     * If the object fails to validate, it will be dropped from the pool, and we will attempt to borrow another.
     *
     * @param bool $testOnBorrow
     */
    public function setTestOnBorrow(bool $testOnBorrow): void
    {
        $this->testOnBorrow = $testOnBorrow;
    }

    /**
     * The indication of whether objects will be validated before being returned to the pool
     *
     * @param bool $testOnReturn
     */
    public function setTestOnReturn(bool $testOnReturn): void
    {
        $this->testOnReturn = $testOnReturn;
    }

    /**
     * Reset the connection to its initial state when it is borrowed from the pool
     *
     * @param bool $resetConnections
     */
    public function setResetConnections(bool $resetConnections): void
    {
        $this->resetConnections = $resetConnections;
    }

    public function getIdleCount(): int
    {
        if (!$this->isInitialized) {
            return 0;
        }

        return $this->idle->length();
    }

    public function getTotalCount(): int
    {
        return $this->connections->count();
    }

    public function getStats(): PoolStats
    {
        $stats = new PoolStats();

        $stats->maxActive = $this->getMaxActive();

        $stats->totalCount = $this->getTotalCount();
        $stats->idle = $this->getIdleCount();
        $stats->inUse = $stats->totalCount - $stats->idle;

        $stats->waitCount = $this->waitCount;
        $stats->waitDuration = $this->waitDuration;
        $stats->maxIdleTimeClosed = $this->maxIdleTimeClosedCount;
        $stats->maxLifeTimeClosed = $this->maxLifeTimeClosedCount;

        return $stats;
    }

    public function getMaxActive(): int
    {
        return $this->maxActive;
    }

    public function getMinActive(): int
    {
        return $this->minActive;
    }

    public function getMaxWaitTime(): float
    {
        return $this->maxWaitTime;
    }

    public function getValidationInterval(): float
    {
        return $this->validationInterval;
    }

    public function getMaxIdleTime(): int
    {
        return $this->maxIdleTime;
    }

    public function getMaxLifeTime(): int
    {
        return $this->maxLifeTime;
    }

    public function getTestOnBorrow(): bool
    {
        return $this->testOnBorrow;
    }

    public function getTestOnReturn(): bool
    {
        return $this->testOnReturn;
    }

    public function getResetConnections(): bool
    {
        return $this->resetConnections;
    }

    /**
     * Use this method only for read-only purpose
     *
     * @return SplObjectStorage<ConnectionInterface, int>
     */
    protected function getConnections(): SplObjectStorage
    {
        return $this->connections;
    }

    protected function getConnectionConfig(): ConnectionConfigInterface
    {
        return $this->connectionConfig;
    }

    /**
     * @throws PoolIsClosedException If the pool has been closed.
     * @throws BorrowTimeoutException when connection pop timeout reached
     */
    protected function pop(): ConnectionInterface
    {
        if (!$this->isInitialized) {
            throw new PoolIsClosedException('Connection pool is closed, call $pool->init();');
        }

        // Prevent simultaneous connection creation.
        while ($this->lock->isLocked()) {
            $this->lock->wait();
        }

        // Max connection count has not been reached, so open another connection.
        if ($this->idle->isEmpty() && $this->connections->count() < $this->maxActive) {
            return $this->createConnection();
        }

        return $this->popConnectionFromIdle();
    }

    /**
     * @return ConnectionInterface
     *
     * @throws PoolIsClosedException
     * @throws BorrowTimeoutException
     */
    private function popConnectionFromIdle(): ConnectionInterface
    {
        $waitStart = null;

        if ($this->idle->isEmpty()) {
            $waitStart = microtime(true);

            // integer overflow
            if ($this->waitCount === PHP_INT_MAX) {
                $this->waitCount = 0;
                $this->waitDuration = 0.0;
            }

            $this->waitCount++;
        }

        /** @var ConnectionInterface|false $connection */
        $connection = $this->idle->pop($this->maxWaitTime);

        if ($waitStart !== null) {
            $waitTime = microtime(true) - $waitStart;
            $this->waitDuration += $waitTime;

            // float overflow
            if ($this->waitDuration === PHP_FLOAT_MAX) {
                $this->waitDuration = $waitTime;
                $this->waitCount = 1;
            }
        }

        if ($this->idle->errCode === SWOOLE_CHANNEL_OK) {
            // connection pool was resized
            if ($connection === false) {
                return $this->pop();
            }

            // remove dead connection
            if ($this->testOnBorrow && !$connection->isAlive()) {
                $this->connections->detach($connection);

                // create new connection instead of dead one
                $connection = $this->createConnection();

                return $connection;
            }

            if ($this->isConnectionExpired($connection, -1)) {
                $this->removeExpiredConnection($connection);

                // create new connection instead of expired one
                $connection = $this->createConnection();

                return $connection;
            }

            if ($this->resetConnections) {
                $this->resetConnection($connection);
            }

            return $connection;
        }

        if ($this->idle->errCode === SWOOLE_CHANNEL_TIMEOUT) {
            throw new BorrowTimeoutException();
        }

        throw new PoolIsClosedException('Pool closed before an active connection could be obtained');
    }

    /**
     * @param ConnectionInterface $connection
     *
     * @return int push status (read PUSH_* constant docs)
     */
    protected function push(ConnectionInterface $connection): int
    {
        // discard connection when pool is not initialized
        if (!$this->isInitialized) {
            $this->removeConnection($connection);

            return self::PUSH_POOL_NOT_INITIALIZED;
        }

        // discard connection that is not part of this pool
        if (!$this->connections->contains($connection)) {
            $this->removeConnection($connection);

            return self::PUSH_CONN_NOT_PART_OF_POOL;
        }

        // discard connection when pool has reached the maximum connection limit
        if ($this->idle->isFull()) {
            $this->removeConnection($connection);

            return self::PUSH_LIMIT_REACHED;
        }

        // discard dead connections
        if ($this->testOnReturn && !$connection->isAlive()) {
            $this->removeConnection($connection);

            return self::PUSH_DEAD_CONNECTION;
        }

        // discard connection due to max life time
        if ($this->isConnectionExpired($connection, -1)) {
            $this->removeExpiredConnection($connection);

            return self::PUSH_MAX_LIFE_TIME;
        }

        if (!$this->pushConnectionToIdle($connection)) {
            return self::PUSH_POOL_CLOSED;
        }

        return self::PUSH_OK;
    }

    private function pushConnectionToIdle(ConnectionInterface $connection): bool
    {
        if (!$this->idle->push($connection)) {
            $this->removeConnection($connection);

            return false;
        }

        return true;
    }

    private function createConnection(): ConnectionInterface
    {
        $this->lock->lock();

        try {
            $connection = $this->connector->connect($this->connectionConfig);
            $this->connections->attach($connection, time());
        } finally {
            $this->lock->unlock();
        }

        return $connection;
    }

    private function removeConnection(ConnectionInterface $connection): void
    {
        $this->connections->detach($connection);

        if ($connection->isAlive()) {
            Coroutine::create(
                static function (ConnectionInterface $connection): void {
                    try {
                        $connection->close();
                    } catch (Throwable $e) {
                        // ignore connection close errors
                    }
                },
                $connection
            );
        }
    }

    private function validateConnections(): void
    {
        $now = time();

        /** @var ConnectionInterface[] $connections */
        $connections = [];

        while (!$this->idle->isEmpty()) {
            $connection = $this->idle->pop();
            // connection pool is closed
            if (false === $connection) {
                return;
            }

            // do not keep dead connections
            if ($connection->isAlive()) {
                $connections[] = $connection;
            }
        }

        $connectionsCount = $this->connections->count();

        foreach ($connections as $connection) {
            // remove idle connections
            if ($this->maxIdleTime > 0
                && $connectionsCount > $this->minActive
                && $connection->getLastUsedAt() + $this->maxIdleTime <= $now) {
                $connectionsCount--;
                $this->removeMaxIdleTimeConnection($connection);

                continue;
            }

            // remove expired connections
            if ($this->isConnectionExpired($connection, $now)) {
                $connectionsCount--;
                $this->removeExpiredConnection($connection);

                continue;
            }

            $this->pushConnectionToIdle($connection);
        }

        $this->fillPool();
    }

    private function fillPool(): void
    {
        while ($this->connections->count() < $this->minActive) {
            // connection pool is closed or connection is currently being created by another coroutine
            if (!$this->isInitialized || $this->lock->isLocked()) {
                break;
            }

            try {
                $connection = $this->createConnection();
            } catch (Throwable $e) {
                // stop on connection errors
                return;
            }

            if (!$this->pushConnectionToIdle($connection)) {
                // connection pool is closed
                return;
            }
        }
    }

    private function startValidationTimer(): void
    {
        if ($this->validationInterval > 0) {
            $this->validationTimer->start();
        }
    }

    private function stopValidationTimer(): void
    {
        if ($this->validationTimer->isStarted()) {
            $this->validationTimer->stop();
        }
    }

    private function removeMaxIdleTimeConnection(ConnectionInterface $connection): void
    {
        if ($this->maxIdleTimeClosedCount === PHP_INT_MAX) {
            $this->maxIdleTimeClosedCount = 0;
        }

        $this->maxIdleTimeClosedCount++;

        $this->removeConnection($connection);
    }

    private function removeExpiredConnection(ConnectionInterface $connection): void
    {
        if ($this->maxLifeTimeClosedCount === PHP_INT_MAX) {
            $this->maxLifeTimeClosedCount = 0;
        }

        $this->maxLifeTimeClosedCount++;

        $this->removeConnection($connection);
    }

    private function isConnectionExpired(ConnectionInterface $connection, int $time): bool
    {
        if ($this->maxLifeTime <= 0) {
            return false;
        }

        if ($time === -1) {
            $time = time();
        }

        return $this->connections[$connection] + $this->maxLifeTime <= $time;
    }
}
