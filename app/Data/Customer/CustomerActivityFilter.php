<?php

namespace App\Data\Customer;

/**
 * The criteria submitted from the "Activités Clients" page. Shared by the map
 * and the PDF export so both always return exactly the same customers.
 */
final readonly class CustomerActivityFilter
{
    public const TYPE_WITH_INVOICE = 'with_invoice';

    public const TYPE_BY_SECTOR = 'by_sector';

    public const TYPE_ABOVE_AMOUNT = 'above_amount';

    public const TYPE_ABOVE_AVERAGE_AMOUNT = 'above_average_amount';

    public const TYPE_CHURNING = 'churning';

    public function __construct(
        public string $filterType,
        public string $startDate,
        public string $endDate,
        public ?int $sectorId = null,
        public ?int $minimumAmount = null,
        public ?int $minimumAverageAmount = null,
        public ?int $inactiveDays = null,
    ) {}

    public function isChurning(): bool
    {
        return $this->filterType === self::TYPE_CHURNING;
    }
}
