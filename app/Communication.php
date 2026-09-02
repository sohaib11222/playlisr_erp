<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Communication extends Model
{
    protected $guarded = ['id'];

    protected $dates = ['resolved_at'];

    protected $casts = [
        'is_priority' => 'boolean',
    ];

    const CHANNELS = [
        'phone_1' => 'Phone Line 1 (Quo)',
        'phone_2' => 'Phone Line 2 (Quo)',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'other' => 'Other',
    ];

    const TOPICS = [
        'unhappy_customer' => 'Unhappy Customer',
        'shipping' => 'Shipping',
        'stock' => 'Stock Inquiry',
        'events' => 'Event Question',
        'careers' => 'Career Question',
        'partnerships' => 'Partnership',
        'general' => 'General Inquiry',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
