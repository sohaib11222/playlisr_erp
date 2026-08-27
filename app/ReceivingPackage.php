<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ReceivingPackage extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['received_at'];

    public $log_properties = ['package_type', 'location_id', 'bin_location', 'status'];

    public static $packageTypes = [
        'mail' => 'Mail (Envelope)',
        'box' => 'Box',
        'bag' => 'Bag of Product',
        'retail_delivery' => 'Retail Delivery (Walmart, Instacart, etc.)',
        'listening_event' => 'Listening Event Box',
        'other' => 'Other',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(ReceivingItem::class);
    }

    public function purchaseOrders()
    {
        return $this->belongsToMany(Transaction::class, 'receiving_package_purchase_orders', 'receiving_package_id', 'transaction_id');
    }
}
