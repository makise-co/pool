<?php

declare(strict_types=1);

namespace MakiseCo\Pool;

use Closure;
use InvalidArgumentException;
use MakiseCo\Connection\ConnectionConfigInterface;
use MakiseCo\Connection\ConnectionInterface;
use MakiseCo\Connection\ConnectorInterface;
use MakiseCo\EvPrimitives\Deferred;
use MakiseCo\EvPrimitives\Timer;
use MakiseCo\Pool\Exception\BorrowTimeoutException;
use MakiseCo\Pool\Exception\PoolIsClosedException;
use SplObjectStorage;
use Swoole\Coroutine;
use Throwable;

use function time;

use const SWOOLE_CHANNEL_OK;
use const SWOOLE_CHANNEL_TIMEOUT;

abstract class Pool implements PoolInterface
{
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
    private float $validateConnectionsInterval = 5.0;

    /**
     * The minimum amount of time (seconds) a connection may sit idle in the pool before it is eligible for closing
     */
    private int $maxIdleTime = 60;

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
     * Created connections storage
     *
     * @var SplObjectStorage<ConnectionInterface, null>|ConnectionInterface[]
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
    private ?Deferred $deferred = null;

    private Timer $validateConnectionsTimer;

    abstract protected function createDefaultConnector(): ConnectorInterface;

    public function __construct(
        ConnectionConfigInterface $connConfig,
        ?ConnectorInterface $connector = null
    ) {
        $this->connectionConfig = $connConfig;
        $this->connector = $connector ?? $this->createDefaultConnector();

        $this->connections = new SplObjectStorage();

        $this->validateConnectionsTimer = new Timer(
            (int)($this->validateConnectionsInterval * 1000),
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

        // create initial connections
        if ($this->minActive > 0) {
            Coroutine::create(function () {
                try {
                    while ($this->connections->count() < $this->maxActive) {
                        $connection = $this->connector->connect($this->connectionConfig);

                        $this->connections->attach($connection);

                        if (!$this->idle->push($connection, 0.001)) {
                            $this->connections->detach($connection);

                            break;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore create connection errors
                }
            });
        }

        $this->startValidateConnectionsTimer();
    }

    public function close(): void
    {
        if (!$this->isInitialized) {
            return;
        }

        $this->isInitialized = false;

        $this->stopValidateConnectionsTimer();

        // forget all connection instances
        foreach ($this->connections as $connection) {
            $this->connections->detach($connection);
        }

        Coroutine::create(function () {
            // close all idle connections
            while (!$this->idle->isEmpty()) {
                $connection = $this->idle->pop();
                if (false === $connection) {
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

        $this->maxActive = $maxActive;

        // minActive cannot be greater than maxActive
        if ($this->minActive > $this->maxActive) {
            $this->minActive = $this->maxActive;
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
     * Set the number of seconds to sleep between runs of the idle connection validation/cleaner timer.
     * This value should not be set under 1 second.
     * It dictates how often we check for idle, abandoned connections, and how often we validate idle connections.
     *
     * Zero value will disable connections checking.
     *
     * @param float $validateConnectionsInterval seconds with milliseconds precision
     *
     * @throws InvalidArgumentException when $idleCheckInterval is less than 0
     */
    public function setValidateConnectionsInterval(float $validateConnectionsInterval): void
    {
        if ($validateConnectionsInterval < 0) {
            throw new InvalidArgumentException('validateConnectionsInterval should be a positive value');
        }

        $this->validateConnectionsInterval = $validateConnectionsInterval;

        if ($validateConnectionsInterval === 0.0) {
            // stop timer on zero idle check interval
            if ($this->validateConnectionsTimer->isStarted()) {
                $this->validateConnectionsTimer->stop();
            }
        } else {
            $this->validateConnectionsTimer->setInterval((int)($validateConnectionsInterval * 1000));
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
        while ($this->deferred !== null) {
            try {
                $this->deferred->wait();
            } catch (Throwable $e) {
            }
        }

        // Max connection count has not been reached, so open another connection.
        if ($this->idle->isEmpty() && $this->connections->count() < $this->maxActive) {
            $this->deferred = new Deferred();

            try {
                $connection = $this->connector->connect($this->connectionConfig);
                $this->connections->attach($connection);
            } finally {
                $deferred = $this->deferred;
                $this->deferred = null;

                $deferred->resolve(null);
            }

            return $connection;
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
        /** @var ConnectionInterface $connection */
        $connection = $this->idle->pop($this->maxWaitTime);

        if ($this->idle->errCode === SWOOLE_CHANNEL_OK) {
            // remove dead connection
            if ($this->testOnBorrow && !$connection->isAlive()) {
                $this->connections->detach($connection);

                // create new connection instead of dead one
                $connection = $this->connector->connect($this->connectionConfig);
                $this->connections->attach($connection);
            }

            return $connection;
        }

        if ($this->idle->errCode === SWOOLE_CHANNEL_TIMEOUT) {
            throw new BorrowTimeoutException();
        }

        throw new PoolIsClosedException('Pool closed before an active connection could be obtained');
    }

    protected function push(ConnectionInterface $connection): void
    {
        // discard connection when pool is not initialized
        if (!$this->isInitialized) {
            $this->removeConnection($connection);

            return;
        }

        // discard connection when pool reached connections limit
        if ($this->connections->count() > $this->maxActive) {
            $this->removeConnection($connection);

            return;
        }

        // discard dead connections
        if ($this->testOnReturn && !$connection->isAlive()) {
            $this->removeConnection($connection);

            return;
        }

        $this->idle->push($connection);
    }

    protected function removeConnection(ConnectionInterface $connection): void
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

    protected function validateConnections(): void
    {
        $now = time();

        /** @var ConnectionInterface[] $connections */
        $connections = [];

        while (!$this->idle->isEmpty()) {
            $connection = $this->idle->pop();
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
                $this->removeConnection($connection);

                continue;
            }

            $this->idle->push($connection);
        }
    }

    protected function startValidateConnectionsTimer(): void
    {
        if ($this->validateConnectionsInterval > 0) {
            $this->validateConnectionsTimer->start();
        }
    }

    protected function stopValidateConnectionsTimer(): void
    {
        if ($this->validateConnectionsTimer->isStarted()) {
            $this->validateConnectionsTimer->stop();
        }
    }
}
