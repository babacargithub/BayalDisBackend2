<?php

namespace App\Exceptions;

use Exception;

class InvoicePaymentMismatchException extends Exception
{
    //
    protected $message = 'Cannot mark invoice as paid because the payments total amount does not match the invoice amount';
}
