<?php

namespace App\Support;

class XofFormatter
{
    /** Format an amount without decimals and without the currency suffix. */
    public static function format(int|float|null $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ');
    }
}
