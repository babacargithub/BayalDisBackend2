<?php

namespace App\Enums;

enum SalesInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case FullyPaid = 'FULLY_PAID';
}
