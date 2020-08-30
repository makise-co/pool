<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests\Stub;

use MakiseCo\Connection\ConnectionInterface;

use Swoole\Coroutine;

use function time;

class Connection implements ConnectionInterface
{
    private int $id;
    private bool $isAlive = true;
    private int $lastUsedAt;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->lastUsedAt = time();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function close(): void
    {
        if (!$this->isAlive) {
            return;
        }

        // imitate long close operation
        Coroutine::sleep(0.001);

        $this->isAlive = false;
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    public function setIsAlive(bool $isAlive): void
    {
        $this->isAlive = $isAlive;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(int $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }
}
