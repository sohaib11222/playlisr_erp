<?php

namespace App\Services;

/**
 * When to look at Clover for Nick's clock-in (Sarah, 2026-10-12: don't poll
 * all day). Pure logic, unix timestamps, no framework calls, so it's easy
 * to test.
 *
 * - Scheduled Sling shift(s) today: poll from 15 min before to 30 min
 *   after each start (the job runs every 5 min).
 * - No Sling shift today: don't poll.
 * - Sling can't be read (no fresh sync): one check at his usual start and
 *   every 10 min until the fallback end.
 * - Clock-in already found today: stop.
 */
class ShippingClockInWindow
{
    const BEFORE = 15 * 60;
    const AFTER = 30 * 60;

    /**
     * @param int        $now          unix time
     * @param int[]|null $shiftStarts  today's scheduled start times (unix), null when Sling can't be read
     * @param bool       $foundToday   clock-in already posted today
     * @param int        $fallbackFrom unix time of his usual start today
     * @param int        $fallbackTo   unix time to give up today
     * @return array ['poll' => bool, 'since' => int|null, 'why' => string]
     */
    public static function decide(int $now, ?array $shiftStarts, bool $foundToday, int $fallbackFrom, int $fallbackTo): array
    {
        if ($foundToday) {
            return ['poll' => false, 'since' => null, 'why' => 'already found today'];
        }
        if ($shiftStarts === null) {
            if ($now < $fallbackFrom || $now > $fallbackTo) {
                return ['poll' => false, 'since' => null, 'why' => 'Sling unreadable, outside fallback window'];
            }
            // every 10 minutes: the first run at/after the usual start, then each 10-minute step
            $minutesIn = intdiv($now - $fallbackFrom, 60);
            if ($minutesIn % 10 >= 5) {
                return ['poll' => false, 'since' => null, 'why' => 'Sling unreadable, between 10-minute checks'];
            }
            return ['poll' => true, 'since' => $fallbackFrom - self::BEFORE, 'why' => 'Sling unreadable, fallback check'];
        }
        if (!$shiftStarts) {
            return ['poll' => false, 'since' => null, 'why' => 'no Sling shift today'];
        }
        foreach ($shiftStarts as $start) {
            if ($now >= $start - self::BEFORE && $now <= $start + self::AFTER) {
                return ['poll' => true, 'since' => $start - self::BEFORE - 15 * 60, 'why' => 'around scheduled start'];
            }
        }
        return ['poll' => false, 'since' => null, 'why' => 'not near a scheduled start'];
    }
}
