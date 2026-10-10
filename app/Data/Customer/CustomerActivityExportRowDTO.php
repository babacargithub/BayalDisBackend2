<?php

namespace App\Data\Customer;

use Carbon\CarbonInterface;

final readonly class CustomerActivityExportRowDTO
{
    public function __construct(
        public int $customerId,
        public string $name,
        public ?string $address,
        public ?string $phoneNumber,
        public ?string $beatLabel,
        public int $totalDebtAmount,
        public ?CarbonInterface $lastActiveAt,
        public int $totalPaidAmount,
        public ?string $lastServingCommercialName,
    ) {}
}
