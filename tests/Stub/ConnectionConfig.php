<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Tests\Stub;

use MakiseCo\Connection\ConnectionConfigInterface;

use function sprintf;

class ConnectionConfig implements ConnectionConfigInterface
{
    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 10228;
    }

    public function getUser(): ?string
    {
        return 'makise';
    }

    public function getPassword(): ?string
    {
        return 'el-psy-congroo';
    }

    public function getDatabase()
    {
        return 'cern';
    }

    public function getConnectionString(): string
    {
        return sprintf(
            'host=%s port=%d user=%s password=%s dbname=%s',
            $this->getHost(),
            $this->getPort(),
            $this->getUser(),
            $this->getPassword(),
            $this->getDatabase(),
        );
    }
}
