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
        'email' => 'Email',
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

    /** Canned auto-responses Quo sends automatically (e.g. a missed-call
     * auto-text) — these must never count as a genuine staff reply, or
     * the Hub falsely shows an inquiry as handled when no one actually
     * answered it. Matched case-insensitively against reply text. */
    const AUTO_REPLY_PATTERNS = [
        'sorry we missed your call',
    ];

    public static function isAutoReplyText(string $text): bool
    {
        foreach (self::AUTO_REPLY_PATTERNS as $pattern) {
            if (stripos($text, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a resolution_notes log into individual reply entries with a
     * parsed timestamp where possible. Entries are NOT assumed to be
     * stored in chronological order — a backfill import can attach
     * replies in whatever order Quo's API returned them, which is not
     * always oldest-first — so callers that need "the latest reply"
     * must sort by the parsed time themselves rather than trusting
     * array position.
     */
    public static function parseReplyEntries(?string $notes): array
    {
        $notes = trim((string) $notes);
        if ($notes === '') {
            return [];
        }

        $parts = preg_split('/(?=^\[\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{2}[ap]m\])/mi', $notes, -1, PREG_SPLIT_NO_EMPTY);
        $entries = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $time = null;
            if (preg_match('/^\[(\d{1,2}\/\d{1,2}\s+\d{1,2}:\d{2}[ap]m)\]/i', $part, $m)) {
                try {
                    $time = \Carbon::createFromFormat('n/j g:ia', $m[1], config('app.timezone'))->year(now()->year);
                    if ($time->isFuture() && $time->diffInDays(now()) > 1) {
                        $time->subYear();
                    }
                } catch (\Throwable $e) {
                    $time = null;
                }
            }
            $entries[] = ['text' => $part, 'time' => $time];
        }
        return $entries;
    }

    /**
     * Keep has_real_reply in sync on every save, from every code path
     * (webhook, backfill import, manual edit) — a single source of truth
     * instead of each controller method remembering to set it.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($comm) {
            if (empty($comm->resolution_notes)) {
                $comm->has_real_reply = false;
                return;
            }
            $notes = preg_replace('/<!--.*?-->/', '', (string) $comm->resolution_notes);
            $hasReal = false;
            foreach (self::parseReplyEntries($notes) as $entry) {
                if (!self::isAutoReplyText($entry['text'])) {
                    $hasReal = true;
                    break;
                }
            }
            $comm->has_real_reply = $hasReal;
        });
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
