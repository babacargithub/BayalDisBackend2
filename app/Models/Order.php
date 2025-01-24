<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    const STATUS_WAITING = 'WAITING';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'customer_id',
        'commercial_id',
        'delivery_batch_id',
        'sales_invoice_id',
        'status',
        'should_be_delivered_at',
        'comment',
    ];

    protected $with = ['items.product', 'customer', 'payments'];


    protected $casts = [
        'should_be_delivered_at' => 'datetime',
        'quantity' => 'integer',
    ];
    protected $appends = ['total_price', 'paid_amount', 'is_fully_paid', 'remaining_amount', 'total_amount'];


    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getTotalPriceAttribute()
    {
        return $this->items->sum(function ($item) {
            return $item->price * $item->quantity;
        });
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function addPayment($amount, $paymentMethod = null, $comment = null)
    {
        $payment = $this->payments()->create([
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'comment' => $comment,
        ]);

        $this->updatePaymentStatus();

        return $payment;
    }

    public function updatePaymentStatus()
    {
        
        $this->save();
    }

    public function getRemainingAmountAttribute()
    {
        return max(0, $this->total_amount - $this->paid_amount);
    }

    public function getTotalAmountAttribute()
    {
        return $this->items->sum(function ($item) {
            return $item->price * $item->quantity;
        });
    }

    public function getPaidAmountAttribute()
    {
        return $this->payments()->sum('amount');
    }

    public function getIsFullyPaidAttribute()
    {
        return $this->paid_amount >= $this->total_amount;
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

}
