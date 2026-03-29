<?php

namespace App\Console\Commands;

use App\Models\Vente;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Console\Command;

class RecalculateVentesProfit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ventes:recalculate-profit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate profit for all existing Ventes using historical cost prices';

    /**
     * Execute the console command.
     *
     * This command recalculates profit for all existing Ventes using historical cost prices.
     * It uses the weighted average cost price of stock entries that existed at the time of the sale,
     * which provides a more accurate profit calculation than using the current product cost price.
     */
    public function handle(SalesInvoiceStatsService $salesInvoiceStatsService): int
    {
        $this->info('Starting profit recalculation for all Ventes using historical cost prices...');

        $ventes = Vente::with(['product', 'salesInvoice'])->get();

        $count = 0;
        $errors = 0;

        foreach ($ventes as $vente) {
            try {
                if ($vente->product) {
                    $vente->profit = $salesInvoiceStatsService->calculateProfitForVenteFromHistoricalAverage($vente);
                    $vente->saveQuietly();

                    $count++;
                } else {
                    $this->warn("Skipping Vente ID {$vente->id}: Product not found");
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->error("Error processing Vente ID {$vente->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info('Profit recalculation completed!');
        $this->info("Successfully updated: $count Ventes");

        if ($errors > 0) {
            $this->warn("Errors encountered: $errors");
        }

        return Command::SUCCESS;
    }
}
