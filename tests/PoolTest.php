<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests;

use MakiseCo\Pool\Exception\BorrowTimeoutException;
use MakiseCo\Pool\Tests\Stub\Connection;
use MakiseCo\Pool\Tests\Stub\Pool;
use Swoole\Coroutine;
use Throwable;

use function range;

class PoolTest extends CoroTestCase
{
    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new Pool();
    }

    protected function tearDown(): void
    {
        $this->pool->close();

        parent::tearDown();
    }

    public function testMinActiveCreatedOnStart(): void
    {
        $this->pool->setMaxActive(2);
        $this->pool->setMinActive(2);
        $this->pool->init();

        Coroutine::sleep(0.1);

        self::assertSame(2, $this->pool->getIdleCount());
    }

    public function testMaxConnectionCount(): void
    {
        $this->expectException(BorrowTimeoutException::class);

        $this->pool->setMaxActive(1);
        $this->pool->setMinActive(0);
        $this->pool->setMaxWaitTime(0.001);
        $this->pool->init();

        self::assertSame(0, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());

        $this->pool->pop();

        self::assertSame(1, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());

        try {
            $this->pool->pop();
        } catch (Throwable $e) {
            self::assertSame(1, $this->pool->getTotalCount());
            self::assertSame(0, $this->pool->getIdleCount());

            throw $e;
        }
    }

    public function testIdleConnectionsRemovedAfterTimeout(): void
    {
        $this->pool->setMaxActive(4);
        $this->pool->setMinActive(2);
        $this->pool->setMaxWaitTime(0.001);
        $this->pool->setValidationInterval(0.005);
        $this->pool->setMaxIdleTime(60);
        $this->pool->init();

        $connections = [];

        // mark all connection as expired, but two connections should be kept because minActive value is 2
        for ($i = 0; $i < 4; $i++) {
            $connection = $this->pool->pop();
            $connection->setLastUsedAt(time() - 60);

            $connections[] = $connection;
        }

        foreach ($connections as $connection) {
            $this->pool->push($connection);
        }

        self::assertSame(4, $this->pool->getIdleCount());
        self::assertSame(4, $this->pool->getTotalCount());

        Coroutine::sleep(0.010);

        self::assertSame(2, $this->pool->getIdleCount());
        self::assertSame(2, $this->pool->getTotalCount());

        $stats = $this->pool->getStats();

        self::assertSame(2, $stats->maxIdleTimeClosed);
    }

    public function testConnMaxLifeTime(): void
    {
        $this->pool->setMaxActive(4);
        $this->pool->setMinActive(0);
        $this->pool->setMaxWaitTime(0.001);
        $this->pool->setValidationInterval(0.005);
        $this->pool->setMaxIdleTime(600);
        $this->pool->setMaxLifeTime(2);
        $this->pool->init();

        $connections = [];

        for ($i = 0; $i < 4; $i++) {
            $connection = $this->pool->pop();
            $connections[] = $connection;
        }
        foreach ($connections as $connection) {
            $this->pool->push($connection);
        }

        $reflection = new \ReflectionClass($this->pool);
        $parent = $reflection->getParentClass();
        /** @var \ReflectionClass|false $parent */
        if (false === $parent) {
            self::fail('Cannot get parent class of pool');
            return;
        }

        // bypass PhpStorm code analyse bug
        $func = static function (\ReflectionClass $class, string $name): \ReflectionProperty {
            return $class->getProperty($name);
        };

        $property = $func($parent, 'connections');
        $property->setAccessible(true);

        /** @var \SplObjectStorage<Connection, int>|Connection[] $connections */
        $connections = $property->getValue($this->pool);

        // mark connections as expired
        foreach ($connections as $connection) {
            $connections[$connection] -= 2;
        }

        Coroutine::sleep(0.010);

        self::assertSame(0, $this->pool->getIdleCount());
        self::assertSame(0, $this->pool->getTotalCount());

        $stats = $this->pool->getStats();

        self::assertSame(4, $stats->maxLifeTimeClosed);

        $connection = $this->pool->pop();
        $connections[$connection] = time() - 2;
        $this->pool->push($connection);

        self::assertSame(0, $this->pool->getIdleCount());
        self::assertSame(0, $this->pool->getTotalCount());

        $connection = $this->pool->pop();
        $this->pool->push($connection);

        $connections[$connection] = time() - 2;
        $newConnection = $this->pool->pop();

        self::assertNotSame($connection->getId(), $newConnection->getId());
    }

    public function testIdleConnectionResolved(): void
    {
        $this->pool->setMaxActive(1);
        $this->pool->setMinActive(0);
        $this->pool->setMaxWaitTime(0.5);
        $this->pool->init();

        Coroutine::sleep(0.001);

        self::assertSame(0, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());

        $connection = $this->pool->pop();

        // return connection to the pool from background task
        Coroutine::create(function () use ($connection) {
            Coroutine::sleep(0.001);
            $this->pool->push($connection);
        });

        self::assertSame(1, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());

        $this->pool->push($this->pool->pop());

        self::assertSame(1, $this->pool->getTotalCount());
        self::assertSame(1, $this->pool->getIdleCount());
    }

    public function testSimultaneousPop(): void
    {
        $this->pool->setMaxActive(1);
        $this->pool->setMinActive(0);
        $this->pool->setMaxWaitTime(0.5);
        $this->pool->init();

        $tasksCount = 5;
        $ch = new Coroutine\Channel($tasksCount);

        $task = function (Coroutine\Channel $ch, int $num) {
            $connection = $this->pool->pop();

            self::assertSame(1, $this->pool->getTotalCount());

            Coroutine::sleep(0.003);
            $this->pool->push($connection);

            $ch->push($num);
        };

        for ($i = 0; $i < $tasksCount; $i++) {
            Coroutine::create($task, $ch, $i);
        }

        $results = [];
        for ($i = 0; $i < $tasksCount; $i++) {
            $results[] = $ch->pop();
        }

        self::assertSame(range(0, $tasksCount - 1), $results);
    }

    public function testReturnDeadConnection(): void
    {
        $this->pool->setMaxActive(1);
        $this->pool->init();

        $connection = $this->pool->pop();
        $connection->close();
        $this->pool->push($connection);

        self::assertSame(0, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());
    }

    public function testBorrowDeadConnection(): void
    {
        $this->pool->setMaxActive(1);
        $this->pool->init();

        $connection = $this->pool->pop();
        $this->pool->push($connection);

        $connection->close();

        $newConnection = $this->pool->pop();

        self::assertNotSame($newConnection->getId(), $connection->getId());

        self::assertSame(1, $this->pool->getTotalCount());
        self::assertSame(0, $this->pool->getIdleCount());
    }

    public function testConnectionReset(): void
    {
        $this->pool->setResetConnections(true);
        $this->pool->init();

        $this->pool->push($this->pool->pop());

        self::assertSame(322, $this->pool->pop()->getLastUsedAt());
    }

    public function testGetStats(): void
    {
        $this->pool->setMaxActive(2);
        $this->pool->init();

        $conn1 = $this->pool->pop();

        $stats = $this->pool->getStats();

        self::assertSame(2, $stats->maxActive);
        self::assertSame(1, $stats->totalCount);
        self::assertSame(1, $stats->inUse);
        self::assertSame(0, $stats->idle);
        self::assertSame(0, $stats->waitCount);
        self::assertSame(0.0, $stats->waitDuration);

        $conn2 = $this->pool->pop();

        $stats = $this->pool->getStats();

        self::assertSame(2, $stats->maxActive);
        self::assertSame(2, $stats->totalCount);
        self::assertSame(2, $stats->inUse);
        self::assertSame(0, $stats->idle);
        self::assertSame(0, $stats->waitCount);
        self::assertSame(0.0, $stats->waitDuration);

        $this->pool->push($conn2);

        $stats = $this->pool->getStats();

        self::assertSame(2, $stats->maxActive);
        self::assertSame(2, $stats->totalCount);
        self::assertSame(1, $stats->inUse);
        self::assertSame(1, $stats->idle);
        self::assertSame(0, $stats->waitCount);
        self::assertSame(0.0, $stats->waitDuration);

        $conn2 = $this->pool->pop();

        $func = function () {
            $conn = $this->pool->pop();
            Coroutine::sleep(0.001);
            $this->pool->push($conn);
        };

        Coroutine::create($func);
        Coroutine::create($func);

        $this->pool->push($conn1);
        $this->pool->push($conn2);

        Coroutine::sleep(0.004);

        $stats = $this->pool->getStats();

        self::assertSame(2, $stats->maxActive);
        self::assertSame(2, $stats->totalCount);
        self::assertSame(0, $stats->inUse);
        self::assertSame(2, $stats->idle);
        self::assertGreaterThan(0, $stats->waitDuration);
        self::assertSame(2, $stats->waitCount);
        self::assertSame(0, $stats->maxIdleTimeClosed);
        self::assertSame(0, $stats->maxLifeTimeClosed);
    }

    public function testResizeUp(): void
    {
        $this->pool->setMaxActive(1);
        $this->pool->setMinActive(0);
        $this->pool->setMaxWaitTime(0);
        $this->pool->init();

        $ch = new Coroutine\Channel(1);

        $connection = $this->pool->pop();

        Coroutine::create(function () use ($ch) {
            try {
                $connection = $this->pool->pop();
                $this->pool->push($connection);

                $ch->push($connection);
            } catch (Throwable $e) {
                $ch->push($e);
            }
        });

        $this->pool->setMaxActive(2);

        /** @var Connection $result */
        $result = $ch->pop(0.5);

        self::assertInstanceOf(Connection::class, $result);
        self::assertSame(2, $result->getId());

        $this->pool->push($connection);

        self::assertSame(2, $this->pool->getIdleCount());
        self::assertSame(2, $this->pool->getTotalCount());
    }

    public function testResizeDownWithEmptyIdle(): void
    {
        $this->pool->setMaxActive(2);
        $this->pool->setMinActive(0);
        $this->pool->init();

        $connection1 = $this->pool->pop();
        $connection2 = $this->pool->pop();

        self::assertSame(0, $this->pool->getIdleCount());
        self::assertSame(2, $this->pool->getTotalCount());

        $this->pool->setMaxActive(1);

        $this->pool->push($connection1);
        $this->pool->push($connection2);

        self::assertSame(1, $this->pool->getIdleCount());
        self::assertSame(1, $this->pool->getTotalCount());
    }

    public function testResizeDownWithFullIdle(): void
    {
        $initialMaxActive = 4;

        $this->pool->setMaxActive($initialMaxActive);
        $this->pool->setMinActive(0);
        $this->pool->init();

        $connections = [];

        for ($i = 0; $i < $initialMaxActive; $i++) {
            $connections[] = $this->pool->pop();
        }
        foreach ($connections as $connection) {
            $this->pool->push($connection);
        }

        self::assertSame($initialMaxActive, $this->pool->getIdleCount());
        self::assertSame($initialMaxActive, $this->pool->getTotalCount());

        $this->pool->setMaxActive(1);

        self::assertSame(1, $this->pool->getIdleCount());
        self::assertSame(1, $this->pool->getTotalCount());
    }

    public function testResizeDownWithActiveWaiting(): void
    {
        $this->pool->setMaxActive(2);
        $this->pool->init();

        $connection1 = $this->pool->pop();
        $connection2 = $this->pool->pop();

        self::assertSame(0, $this->pool->getIdleCount());
        self::assertSame(2, $this->pool->getTotalCount());

        $ch = new Coroutine\Channel();
        Coroutine::create(function () use ($ch) {
            try {
                $connection = $this->pool->pop();
                $ch->push($connection);
            } catch (Throwable $e) {
                $ch->push($e);
            }
        });

        $this->pool->setMaxActive(1);

        Coroutine::create(function () use ($connection1, $connection2) {
            $this->pool->push($connection1);
            $this->pool->push($connection2);
        });

        /** @var Connection $result */
        $result = $ch->pop(0.5);

        self::assertInstanceOf(Connection::class, $result);
        self::assertSame(1, $result->getId());

        self::assertSame(Pool::PUSH_LIMIT_REACHED, $this->pool->push($result));

        self::assertSame(1, $this->pool->getIdleCount());
        self::assertSame(1, $this->pool->getTotalCount());
    }
}
