<?php

namespace App\Exceptions;

use Exception;

/**
 * A back-office action is not allowed on an invoice. The message is user-facing (French).
 */
class SalesInvoiceOperationException extends Exception
{
    public function __construct(string $message, public readonly string $field = 'error')
    {
        parent::__construct($message);
    }
}
