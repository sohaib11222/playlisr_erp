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

    /**
     * Best-effort keyword classifier for inbound message/voicemail text.
     * Order matters — unhappy-customer signals are checked first since
     * that topic drives priority sorting, and a frustrated shipping
     * question should still surface as unhappy, not just "shipping".
     * Never guaranteed right; staff can always re-tag from the edit modal.
     */
    public static function guessTopic(?string $text): string
    {
        $text = strtolower((string) $text);
        if (trim($text) === '') {
            return 'general';
        }

        $rules = [
            'unhappy_customer' => [
                'refund', 'complain', 'unacceptable', 'ridiculous', 'terrible',
                'worst', 'awful', 'horrible', 'scam', 'never again', 'disappointed',
                'angry', 'upset', 'still hasn\'t', 'still has not', 'no response',
                'ignored', 'rude', 'disrespect', 'cancel my order', 'this is ridiculous',
                'waiting for weeks', 'waiting since', 'demand', 'lawsuit', 'chargeback',
            ],
            'shipping' => [
                'tracking', 'shipped', 'shipment', 'delivery', 'delivered', 'package',
                'order number', 'order #', 'my order', 'usps', 'ups', 'fedex', 'mail',
                'hasn\'t arrived', 'has not arrived', 'when will it ship', 'address',
            ],
            'stock' => [
                'in stock', 'do you have', 'do you still have', 'available', 'restock',
                'sold out', 'condition', 'copies left', 'pressing', 'still have',
                'how many', 'price on', 'price for',
            ],
            'events' => [
                'listening party', 'rsvp', 'in-store event', 'in store event', 'concert',
                'signing', 'the event', 'this event', 'attend', 'doors open',
            ],
            'careers' => [
                'hiring', 'job opening', 'apply', 'resume', 'résumé', 'position open',
                'interview', 'employment', 'job application', 'looking for work',
            ],
            'partnerships' => [
                'partnership', 'collab', 'sponsor', 'wholesale', 'press inquiry',
                'media inquiry', 'vendor', 'feature us', 'brand deal', 'consignment',
            ],
        ];

        foreach ($rules as $topic => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    return $topic;
                }
            }
        }

        return 'general';
    }

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
