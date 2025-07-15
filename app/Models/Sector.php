<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
class Sector extends Model
{
    protected $fillable = [
        'name',
        'boundaries',
        'ligne_id',
        'description'
    ];

    protected $appends = [
        'total_amount_of_ventes',
        'total_debt',
        'total_number_of_ventes'
    ];

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(Ligne::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function visitBatches(): HasMany
    {
        return $this->hasMany(VisitBatch::class);
    }

    public function getTotalAmountOfVentesAttribute(): int
    {
        return (int) DB::select("SELECT SUM(ventes.price * ventes.quantity) as total_amount FROM ventes
        JOIN customers ON ventes.customer_id = customers.id
        WHERE customers.sector_id = ?", [$this->id])[0]->total_amount;
    }

    public function getTotalDebtAttribute(): int
    {
        // Get total debt from direct sales (ventes)
        $ventesDebt = DB::select("
            SELECT COALESCE(SUM(ventes.price * ventes.quantity), 0) as total_amount 
            FROM ventes
            JOIN customers ON ventes.customer_id = customers.id
            WHERE ventes.type ='SINGLE'
            AND customers.sector_id = ? AND ventes.paid = 0
        ", [$this->id])[0]->total_amount;

        // Get total debt from sales invoices considering partial payments
        $invoicesDebt = DB::select("
            SELECT COALESCE(SUM(
                (
                    SELECT SUM(quantity * price)
                    FROM ventes
                    WHERE sales_invoice_id = sales_invoices.id
                    AND  ventes.type ='INVOICE_ITEM'

                ) - COALESCE((
                    SELECT SUM(amount) 
                    FROM payments 
                    WHERE sales_invoice_id = sales_invoices.id
                ), 0)
            ), 0) as total_amount
            FROM sales_invoices
            JOIN customers ON sales_invoices.customer_id = customers.id
            WHERE customers.sector_id = ? 
            AND (
                SELECT SUM(quantity * price)
                FROM ventes
                WHERE sales_invoice_id = sales_invoices.id
                AND ventes.type ='INVOICE_ITEM'
            ) > COALESCE((
                SELECT SUM(amount) 
                FROM payments 
                WHERE sales_invoice_id = sales_invoices.id
            ), 0)
        ", [$this->id])[0]->total_amount;

        return $ventesDebt + $invoicesDebt;
    }

    public function getTotalNumberOfVentesAttribute(): int
    {
        return (int) $this->customers()
            ->join('ventes', 'customers.id', '=', 'ventes.customer_id')
            ->count();
    }
    
} 