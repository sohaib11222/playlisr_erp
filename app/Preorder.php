<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Preorder extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Get the business that owns the preorder.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the contact (customer) for the preorder.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the product for the preorder.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variation for the preorder.
     */
    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }

    /**
     * Get the user who created the preorder.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
