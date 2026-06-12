<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a payment cannot be cancelled (already cancelled, missing
 * accounting context, etc.). The message is user-facing French.
 */
class PaymentCancellationException extends Exception {}
