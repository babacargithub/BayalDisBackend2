<?php

namespace App\Data\Customer;

use Illuminate\Support\Collection;

final readonly class CustomerActivityExportDTO
{
    /**
     * @param  Collection<int, CustomerActivityExportRowDTO>  $rows
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public bool $includesChurningColumns,
        public Collection $rows,
    ) {}
}
