<?php
/**
 * This file is part of the Makise-Co Postgres Client
 * World line: 0.571024a
 *
 * (c) Dmitry K. <coder1994@gmail.com>
 */

declare(strict_types=1);

namespace MakiseCo\Pool;

class PoolConfig implements PoolConfigInterface
{
    private int $minActive;
    private int $maxActive;
    private float $maxWaitTime;
    private float $maxIdleTime;
    private float $idleCheckInterval;

    /**
     * PoolConfig constructor.
     *
     * @param int $minActive The minimum number of active connections
     * @param int $maxActive The maximum number of active connections
     * @param float $maxWaitTime The maximum waiting time for connection, when reached, an exception will be thrown
     * @param float $maxIdleTime The maximum idle time for the connection, when reached,
     *      the connection will be removed from pool, and keep the least $minActive connections in the pool
     * @param float $idleCheckInterval The interval to check idle connection
     */
    public function __construct(
        int $minActive = 0,
        int $maxActive = 1,
        float $maxWaitTime = 5.0,
        float $maxIdleTime = 30.0,
        float $idleCheckInterval = 15.0
    ) {
        $this->minActive = $minActive;
        $this->maxActive = $maxActive;
        $this->maxWaitTime = $maxWaitTime;
        $this->maxIdleTime = $maxIdleTime;
        $this->idleCheckInterval = $idleCheckInterval;
    }

    /**
     * {@inheritDoc}
     */
    public function getMinActive(): int
    {
        return $this->minActive;
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxActive(): int
    {
        return $this->maxActive;
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxWaitTime(): float
    {
        return $this->maxWaitTime;
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxIdleTime(): float
    {
        return $this->maxIdleTime;
    }

    /**
     * {@inheritDoc}
     */
    public function getIdleCheckInterval(): float
    {
        return $this->idleCheckInterval;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'minActive' => $this->minActive,
            'maxActive' => $this->maxActive,
            'maxWaitTime' => $this->maxWaitTime,
            'maxIdleTime' => $this->maxIdleTime,
            'idleCheckInterval' => $this->idleCheckInterval,
        ];
    }
}
