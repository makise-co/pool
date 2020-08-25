<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests\Stub;

use MakiseCo\Connection\ConnectionConfigInterface;
use MakiseCo\Connection\ConnectorInterface;
use Swoole\Coroutine;

class Connector implements ConnectorInterface
{
    private int $count = 0;

    public function connect(ConnectionConfigInterface $config): Connection
    {
        // imitate long connection operation
        Coroutine::sleep(0.001);

        return new Connection(++$this->count);
    }
}
