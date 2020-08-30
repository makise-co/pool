<?php

declare(strict_types=1);

namespace MakiseCo\Pool;

class PoolStats
{
    /** Maximum number of open connections to the database */
    public int $maxActive;

    // Pool Status

    /** The number of established connections both in use and idle */
    public int $totalCount;

    /** The number of connections currently in use */
    public int $inUse;

    /** The number of idle connections */
    public int $idle;

    // Counters

    /** The total number of connections waited for */
    public int $waitCount;

    /** The total time (seconds) blocked waiting for available connections */
    public float $waitDuration;

    /** The total number of connections closed due to setMaxIdleTime */
    public int $maxIdleTimeClosed;

    /** The total number of connections closed due to setMaxLifeTime */
    public int $maxLifeTimeClosed;
}
