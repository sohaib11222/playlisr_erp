<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CustomerPickup extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['picked_up_at'];

    protected $casts = [
        'is_paid' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Cashier / user who marked the pickup as complete.
     */
    public function pickedUpByUser()
    {
        return $this->belongsTo(User::class, 'picked_up_by_user_id');
    }
}
