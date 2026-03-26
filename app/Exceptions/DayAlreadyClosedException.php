<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when "Clôturer Journée" is attempted on a caisse that has already
 * been locked for the given date.
 */
class DayAlreadyClosedException extends RuntimeException {}
