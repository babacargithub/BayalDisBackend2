<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Warehouse GPS coordinates
    |--------------------------------------------------------------------------
    |
    | Used as the origin point when sorting beat customers by proximity.
    | Override via WAREHOUSE_LATITUDE / WAREHOUSE_LONGITUDE environment variables.
    |
    */
    'warehouse' => [
        'latitude' => (float) env('WAREHOUSE_LATITUDE', 14.753016680035563),
        'longitude' => (float) env('WAREHOUSE_LONGITUDE', -17.468550395271897),
    ],
];
