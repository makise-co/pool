<?php
/*
 * This file is part of the Makise-Co Framework
 *
 * World line: 0.571024a
 * (c) Dmitry K. <coder1994@gmail.com>
 */

declare(strict_types=1);

namespace MakiseCo\Pool;

interface PoolConfigInterface
{
    /**
     * @return int The minimum number of active connections
     */
    public function getMinActive(): int;

    /**
     * @return int The maximum number of active connections
     */
    public function getMaxActive(): int;

    /**
     * @return float The maximum waiting time for connection, when reached, an exception will be thrown
     */
    public function getMaxWaitTime(): float;

    /**
     * @return float The maximum idle time for the connection, when reached,
     *      the connection will be removed from pool, and keep the least $minActive connections in the pool
     */
    public function getMaxIdleTime(): float;

    /**
     * @return float The interval to check idle connection
     */
    public function getIdleCheckInterval(): float;

    /**
     * @return array
     */
    public function toArray(): array;
}
