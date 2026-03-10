<?php

namespace App\Enums;

enum SalesInvoiceStatus: string
{
    /**
     * Invoice is being composed in the back-office and has not been sent to the customer yet.
     * This is the default status when an invoice is created directly in the DB.
     */
    case Draft = 'DRAFT';

    /**
     * Invoice has been finalised and issued to the customer (e.g. via the mobile app).
     * It is awaiting payment. No payment has been recorded yet.
     */
    case Issued = 'ISSUED';

    /** At least one payment has been recorded but the invoice is not fully settled. */
    case PartiallyPaid = 'PARTIALLY_PAID';

    /** All payments have been received and the invoice total is fully covered. */
    case FullyPaid = 'FULLY_PAID';
}
