<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a commercial tries to create a sale without having recorded
 * today's departure odometer reading on their beat round.
 *
 * The mobile app should intercept ERROR_CODE and redirect to the odometer
 * recording screen rather than displaying a generic error.
 */
class OdometerNotRecordedException extends Exception
{
    public const ERROR_CODE = 'ODOMETER_NOT_RECORDED';

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
