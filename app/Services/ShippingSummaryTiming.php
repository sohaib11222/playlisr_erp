<?php

namespace App\Services;

/**
 * When to post the daily shipping summary (Sarah, 2026-10-12). Pure logic on
 * unix timestamps so it's easy to test.
 *
 * - Nick has Sling shift(s) today: 15 min after the last one ends (his End
 *   Shift in the ERP posts it earlier if he does that first).
 * - No Sling shift today (he's off Saturdays): 9 AM.
 * - Sling can't be read: assume he works unless it's Saturday, shift end
 *   9:30 AM, so post at 9:45 AM; Saturdays 9 AM.
 * - Already posted today: nothing.
 */
class ShippingSummaryTiming
{
    const AFTER_END = 15 * 60;

    /**
     * @param int        $now
     * @param array|null $shifts       [[startTs, endTs], ...] today, null when Sling is unreadable
     * @param bool       $postedToday
     * @param int        $nineAm       today 9:00 AM (LA) as unix time
     * @param int        $fallbackEnd  today 9:30 AM (LA) as unix time
     * @param bool       $isSaturday
     * @return string|null trigger name when it's time to post, else null
     */
    public static function due(int $now, ?array $shifts, bool $postedToday, int $nineAm, int $fallbackEnd, bool $isSaturday): ?string
    {
        if ($postedToday) {
            return null;
        }
        if ($shifts === null) {
            if ($isSaturday) {
                return $now >= $nineAm ? 'day_off_9am' : null;
            }
            return $now >= $fallbackEnd + self::AFTER_END ? 'fallback_shift_end' : null;
        }
        if (!$shifts) {
            return $now >= $nineAm ? 'day_off_9am' : null;
        }
        $lastEnd = max(array_map(function ($s) { return (int) $s[1]; }, $shifts));
        return $now >= $lastEnd + self::AFTER_END ? 'sling_shift_end' : null;
    }
}
