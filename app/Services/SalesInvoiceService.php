<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class SalesInvoiceService
{
    public function createSalesInvoice(array $data): SalesInvoice
    {
        return DB::transaction(function () use ($data) {
            // Create the sales invoice
            $salesInvoice = SalesInvoice::create([
                'customer_id' => $data['customer_id'],
                "invoice_number" => "INV-" . date('Ymd') . "-" . str_pad(SalesInvoice::count() + 1, 4, '0', STR_PAD_LEFT),
                "label" => "Facture Vente",
                'paid' => $data['paid'] ?? false,
                'should_be_paid_at' => $data['should_be_paid_at'] ?? null,
            ]);
            $salesInvoice->save();
            $salesInvoice->refresh();
            $totalAmount = 0;

            // Add items to the invoice and update stock
            $itemsArray = [];
            foreach ($data['items'] as $item) {
                $itemAmount = $item['quantity'] * $item['price'];
                $totalAmount += $itemAmount;

                $itemsArray[] = [
                    'sales_invoice_id' => $salesInvoice->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    "type" => "INVOICE_ITEM",
                ];

                // Update product stock using the decrementStock method
               Product::findOrFail($item['product_id'])->decrementStock($item['quantity']);
            }
                Vente::insert($itemsArray);


            // If paid, create payment record
            if ($data['paid']) {
                Payment::create([
                    'sales_invoice_id' => $salesInvoice->id,
                    'amount' => $totalAmount,
                    'payment_method' => $data['payment_method'],
                    'user_id' => request()->user()->id,
                ]);
            }

            return $salesInvoice;
        });
    }
} 