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
     * Find-or-create the row for a given (business, location, day,
     * employee_key). employee_key = null is the legacy "store-level"
     * row; a non-empty string scopes the row to a single cashier so
     * Sarah can sign off "Henry's drawer for today is reconciled"
     * independently from the rest of the store.
     */
    public static function findOrCreateFor(int $businessId, $locationId, string $day, $employeeKey = null)
    {
        // Normalize location_id: HTTP sends strings, so '0' / '' / null all
        // mean "(no location)" bucket. Without this, the strict === checks
        // let string '0' fall through and INSERT with location_id=0, which
        // violates the FK to business_locations(id) and 500s the request.
        $hasLocation = !($locationId === null || $locationId === '' || (int) $locationId === 0);
        $normalizedLocationId = $hasLocation ? (int) $locationId : null;

        $hasEmployee = !($employeeKey === null || $employeeKey === '');
        $normalizedEmployeeKey = $hasEmployee ? strtolower(trim((string) $employeeKey)) : null;

        $q = static::where('business_id', $businessId)->where('day', $day);
        $q = $hasLocation
            ? $q->where('location_id', $normalizedLocationId)
            : $q->whereNull('location_id');
        $q = $hasEmployee
            ? $q->where('employee_key', $normalizedEmployeeKey)
            : $q->whereNull('employee_key');
        $row = $q->first();
        if ($row) return $row;

        return static::create([
            'business_id' => $businessId,
            'location_id' => $normalizedLocationId,
            'day' => $day,
            'employee_key' => $normalizedEmployeeKey,
        ]);
    }
}
