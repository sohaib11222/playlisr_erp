<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ReceivingItem extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['priced_at'];

    public $log_properties = ['sku', 'product_name', 'cost_price', 'msrp', 'pending_sell_price', 'rack', 'status'];

    public function package()
    {
        return $this->belongsTo(ReceivingPackage::class, 'receiving_package_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }

    public function pricedByUser()
    {
        return $this->belongsTo(User::class, 'priced_by');
    }
}
