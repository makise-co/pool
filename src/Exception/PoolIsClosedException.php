<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Exception;

class PoolIsClosedException extends \RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message, 0, null);
    }
}
