<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a financial operation would break the company-wide invariant:
 *
 *   SUM(account.balance) == SUM(caisse.balance)
 *
 * This invariant must hold at all times. Any violation means money has been
 * created or destroyed in the ledger, which is a critical accounting error.
 *
 * Because this exception is thrown inside DB::transaction(), it triggers an
 * automatic rollback — the violating writes are never persisted.
 */
class InvariantException extends RuntimeException {}
