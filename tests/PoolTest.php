<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests;

use MakiseCo\Pool\Exception\BorrowTimeoutException;
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
        $this->pool->setValidateConnectionsInterval(0.005);
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
}
