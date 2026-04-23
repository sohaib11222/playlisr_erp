<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-store, per-day reconciliation record. One row per
 * (business_id, location_id, day) — see migration for why the uniqueness
 * is enforced at the app layer rather than a SQL UNIQUE.
 */
class CloverReconciliation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'day' => 'date',
        'reconciled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    /**
     * Find-or-create the row for a given (business, location, day). Used
     * by both the render path (so we can tell if it's already reconciled)
     * and the save endpoints (so the first click creates the row).
     * location_id = null is a valid key (the "(no location)" bucket).
     */
    public static function findOrCreateFor(int $businessId, $locationId, string $day)
    {
        $q = static::where('business_id', $businessId)->where('day', $day);
        $q = $locationId === null || $locationId === 0
            ? $q->whereNull('location_id')
            : $q->where('location_id', (int) $locationId);
        $row = $q->first();
        if ($row) return $row;

        return static::create([
            'business_id' => $businessId,
            'location_id' => ($locationId === 0 || $locationId === null) ? null : (int) $locationId,
            'day' => $day,
        ]);
    }
}
