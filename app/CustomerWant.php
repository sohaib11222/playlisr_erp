<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CustomerWant extends Model
{
    protected $table = 'customer_wants';

    protected $guarded = ['id'];

    public function contact()
    {
        return $this->belongsTo(\App\Contact::class, 'contact_id');
    }

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function fulfiller()
    {
        return $this->belongsTo(\App\User::class, 'fulfilled_by');
    }
}
