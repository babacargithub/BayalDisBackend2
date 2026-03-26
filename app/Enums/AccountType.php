<?php

namespace App\Enums;

enum AccountType: string
{
    /**
     * Main pool holding the remaining proceeds after commissions and cost allocations.
     * Used to fund merchandise repurchases. Exactly one instance exists company-wide.
     */
    case MerchandiseSales = 'MERCHANDISE_SALES';

    /**
     * Accumulated depreciation reserve for a specific vehicle.
     * One instance per vehicle.
     */
    case VehicleDepreciation = 'VEHICLE_DEPRECIATION';

    /**
     * Insurance premium reserve for a specific vehicle.
     * One instance per vehicle.
     */
    case VehicleInsurance = 'VEHICLE_INSURANCE';

    /**
     * Repair savings reserve for a specific vehicle.
     * One instance per vehicle.
     */
    case VehicleRepairReserve = 'VEHICLE_REPAIR_RESERVE';

    /**
     * Routine maintenance reserve for a specific vehicle.
     * One instance per vehicle.
     */
    case VehicleMaintenance = 'VEHICLE_MAINTENANCE';

    /**
     * Fuel cost reserve for a specific vehicle.
     * One instance per vehicle.
     */
    case VehicleFuel = 'VEHICLE_FUEL';

    /**
     * Accumulated commissions owed to a specific commercial.
     * One instance per commercial.
     */
    case CommercialCommission = 'COMMERCIAL_COMMISSION';

    /**
     * Tracks cash physically held by a commercial pending versement to the main caisse.
     * Balance must always equal the commercial's caisse balance.
     * One instance per commercial.
     */
    case CommercialCollected = 'COMMERCIAL_COLLECTED';

    /**
     * Reserve for a specific recurring fixed cost (rent, electricity, etc.).
     */
    case FixedCost = 'FIXED_COST';

    public function label(): string
    {
        return match ($this) {
            self::MerchandiseSales => 'Vente marchandises',
            self::VehicleDepreciation => 'Amortissement véhicule',
            self::VehicleInsurance => 'Réserve assurance',
            self::VehicleRepairReserve => 'Réserve réparation',
            self::VehicleMaintenance => 'Entretien véhicule',
            self::VehicleFuel => 'Carburant',
            self::CommercialCommission => 'Commission commercial',
            self::CommercialCollected => 'Encaissements en attente',
            self::FixedCost => 'Charge fixe',
        };
    }

    public function requiresVehicle(): bool
    {
        return in_array($this, [
            self::VehicleDepreciation,
            self::VehicleInsurance,
            self::VehicleRepairReserve,
            self::VehicleMaintenance,
            self::VehicleFuel,
        ], strict: true);
    }

    public function requiresCommercial(): bool
    {
        return in_array($this, [
            self::CommercialCommission,
            self::CommercialCollected,
        ], strict: true);
    }
}
