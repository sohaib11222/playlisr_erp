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
        'phone_1' => 'Nivessa Pico (Quo)',
        'phone_2' => 'Nivessa Hollywood (Quo)',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
        'other' => 'Other',
    ];

    /** Quo store numbers → our channel codes. Used by QuoWebhookController to
     * route an inbound message/call to the right line without needing the
     * Quo API — matched off the phone number the webhook payload carries. */
    const QUO_NUMBERS = [
        '+12135771648' => 'phone_1', // Nivessa Pico
        '+12136762645' => 'phone_2', // Nivessa Hollywood
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
