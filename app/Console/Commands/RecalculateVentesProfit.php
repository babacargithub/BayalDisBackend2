<?php

namespace App\Console\Commands;

use App\Models\Vente;
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
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting profit recalculation for all Ventes using historical cost prices...');

        // Get all Ventes with their related products
        // We need the product relationship to access product details
        $ventes = Vente::with('product')->get();

        $count = 0;
        $errors = 0;

        foreach ($ventes as $vente) {
            try {
                if ($vente->product) {
                    // Use the Vente model's calculateProfit method to ensure consistency
                    // This will use historical cost prices from StockEntry records
                    $vente->calculateProfit($vente);
                    $vente->save();

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

        $this->info("Profit recalculation completed!");
        $this->info("Successfully updated: $count Ventes");

        if ($errors > 0) {
            $this->warn("Errors encountered: $errors");
        }

        return Command::SUCCESS;
    }
}
