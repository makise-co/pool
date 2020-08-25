<?php

declare(strict_types=1);

namespace MakiseCo\Pool\Exception;

use Throwable;

class BorrowTimeoutException extends \RuntimeException
{
    public function __construct($message = 'Borrow connection timeout', $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
