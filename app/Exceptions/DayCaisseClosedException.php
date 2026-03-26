<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation (e.g. payment creation) is attempted on a commercial caisse
 * that has been locked for the day via "Clôturer Journée".
 */
class DayCaisseClosedException extends RuntimeException {}
