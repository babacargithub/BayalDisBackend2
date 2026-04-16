<?php

namespace App\Enums;

enum StockEntryTransferType: string
{
    /**
     * Stock leaves the warehouse and goes into a car load (or any warehouse decrease).
     */
    case Out = 'out';

    /**
     * Stock returns from a car load back into the warehouse (or any warehouse increase).
     */
    case In = 'in';
}
