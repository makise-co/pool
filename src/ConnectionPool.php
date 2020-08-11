<?php
/*
 * This file is part of the Makise-Co Framework
 *
 * World line: 0.571024a
 * (c) Dmitry K. <coder1994@gmail.com>
 */

declare(strict_types=1);

namespace MakiseCo\Pool;

use Smf\ConnectionPool\ConnectionPool as SmfConnectionPool;
use Smf\ConnectionPool\Connectors\ConnectorInterface;

class ConnectionPool extends SmfConnectionPool
{
    public function __construct(
        PoolConfigInterface $poolConfig,
        ConnectorInterface $connector,
        ConnectionConfigInterface $connectionConfig
    ) {
        parent::__construct(
            $poolConfig->toArray(),
            $connector,
            ['connection_config' => $connectionConfig]
        );
    }
}
