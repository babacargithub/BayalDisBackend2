<?php

namespace App\Data\Vente;

enum PaidStatus
{
    case All;
    case PaidOnly;
    case UnpaidOnly;
}
