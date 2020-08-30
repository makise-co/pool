<?php

declare(strict_types=1);

namespace MakiseCo\Pool;

use MakiseCo\Connection\TransientResource;

interface PoolInterface extends TransientResource
{
    /**
     * Initialize (start) connection pool
     */
    public function init(): void;

    /**
     * Get connection pool statistics
     *
     * @return PoolStats
     */
    public function getStats(): PoolStats;

    /**
     * Get count of connections created by the pool
     *
     * @return int
     */
    public function getTotalCount(): int;

    /**
     * Get idle connections count
     *
     * @return int
     */
    public function getIdleCount(): int;

    /**
     * Get maximum connection count limit
     *
     * @return int
     */
    public function getMaxActive(): int;

    /**
     * Get minimum number of established connections that should be kept in the pool at all times
     *
     * @return int
     */
    public function getMinActive(): int;

    /**
     * Get minimum amount of time (seconds) a connection may sit idle in the pool before it is eligible for closing
     *
     * @return int
     */
    public function getMaxIdleTime(): int;
}
