<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class DiscogsOrder extends Model
{
    protected $table = 'discogs_orders';

    protected $fillable = [
        'business_id',
        'discogs_order_id',
        'order_date',
        'status',
        'total',
        'currency',
        'items_count',
        'buyer',
        'raw_payload',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'total' => 'float',
        'items_count' => 'integer',
        'raw_payload' => 'array',
    ];

    // Statuses Discogs uses to indicate the buyer paid. We treat these as
    // "revenue" — analogous to payment_status='completed' for web orders.
    // "Cancelled" / "Refunded" are excluded from revenue rollups.
    const REVENUE_STATUSES = [
        'Payment Received',
        'In Progress',
        'Shipped',
        'Refund Sent',
        'Refund Pending',
        'Merged',
    ];
}
