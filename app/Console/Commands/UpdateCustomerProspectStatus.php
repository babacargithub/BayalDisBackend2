<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateCustomerProspectStatus extends Command
{
    protected $signature = 'customers:update-prospect-status';
    protected $description = 'Update is_prospect status for all customers based on their ventes';

    public function handle()
    {
        $this->info('Starting to update customer prospect status...');

        try {
            DB::transaction(function () {
                // Get all customers with ventes
                $customersWithVentes = Customer::whereHas('ventes')->get();
                $count = $customersWithVentes->count();

                $this->info("Found {$count} customers with ventes.");

                // Update their prospect status
                foreach ($customersWithVentes as $customer) {
                    if ($customer->is_prospect) {
                        $customer->is_prospect = false;
                        $customer->save();
                        $this->line("Updated customer: {$customer->name}");
                    }
                }
            });

            $this->info('Successfully updated customer prospect status.');
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
} 