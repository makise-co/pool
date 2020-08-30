<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests\Stub;

use MakiseCo\Connection\ConnectionInterface;
use MakiseCo\Connection\ConnectorInterface;

class Pool extends \MakiseCo\Pool\Pool
{
    public function __construct()
    {
        parent::__construct(new ConnectionConfig(), null);
    }

    /**
     * {@inheritDoc}
     *
     * @return Connection
     */
    public function pop(): Connection
    {
        /** @var Connection */
        return parent::pop();
    }

    /**
     * {@inheritDoc}
     *
     * @param Connection|ConnectionInterface $connection
     */
    public function push(ConnectionInterface $connection): int
    {
        return parent::push($connection);
    }

    protected function createDefaultConnector(): ConnectorInterface
    {
        return new Connector();
    }

    /**
     * @param Connection $connection
     */
    protected function resetConnection(ConnectionInterface $connection): void
    {
        $connection->setLastUsedAt(322);
    }
}
