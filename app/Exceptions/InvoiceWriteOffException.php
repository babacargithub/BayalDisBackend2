<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when an invoice cannot be written off (already written off, or it
 * still carries payments). The message is user-facing French.
 */
class InvoiceWriteOffException extends Exception {}
