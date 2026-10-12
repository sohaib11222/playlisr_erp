<?php

namespace App\Utils;

/**
 * Change runs (Sarah 2026-10-11): a manager takes $100 from the drawer to
 * the bank for small bills and coins. Created automatically at register
 * close when the drawer is nearly out of change, or requested from the POS
 * menu. Max one every 7 days per store.
 */
class ChangeRunUtil
{
    const TITLE = 'Change run: $100 to the bank for small bills';

    // Who gets the task per store (they can reassign it).
    const OWNERS = [
        'pico'      => ['Zakary', 'Heimlich'],
        'hollywood' => ['Luis', 'Casanova'],
    ];

    const BANK_LIST = [
        '$20 in $1s (20 bills)',
        '$20 in $5s (4 bills)',
        '$20 in $10s (2 bills)',
        '$20 in $20s (1 bill)',
        '$20 in coins: 1 roll of quarters ($10), 1 roll of dimes ($5), 2 rolls of nickels ($4), 2 rolls of pennies ($1)',
    ];

    /** 'pico' / 'hollywood' / null for a location id. */
    public static function storeKey($location_id)
    {
        $name = (string) optional(\App\BusinessLocation::find($location_id))->name;
        if (stripos($name, 'pico') !== false) return 'pico';
        if (stripos($name, 'holly') !== false) return 'hollywood';
        return null;
    }

    /** The store's change run that isn't finished yet, if any. */
    public static function openTask($business_id, $storeKey)
    {
        return \App\WeeklyTask::where('business_id', $business_id)
            ->where('store', $storeKey)
            ->where('title', 'like', 'Change run%')
            ->where('status', '!=', 'complete')
            ->latest('id')
            ->first();
    }

    /** True when the store already had a change run in the last 7 days. */
    public static function ranThisWeek($business_id, $storeKey): bool
    {
        return \App\WeeklyTask::where('business_id', $business_id)
            ->where('store', $storeKey)
            ->where('title', 'like', 'Change run%')
            ->where('created_at', '>', \Carbon\Carbon::now()->subDays(7))
            ->exists();
    }

    /** Create the task for the store manager and queue their text. */
    public static function createTask($business_id, $storeKey, string $reason, $fallbackUserId)
    {
        [$first, $last] = self::OWNERS[$storeKey];
        $owner = \App\User::where('business_id', $business_id)
            ->where('first_name', 'like', $first . '%')
            ->where('last_name', 'like', $last . '%')
            ->where('status', 'active')
            ->first();

        $description = $reason . "\n\nTake \$100 from the drawer to the bank and ask for:\n- "
            . implode("\n- ", self::BANK_LIST)
            . "\n\nIn the POS, open the menu (top right) > Change run, and tap the buttons there when you leave and when you put the change in the drawer. You can reassign this task to someone else in the store.";

        $task = \App\WeeklyTask::create([
            'business_id' => $business_id,
            'title' => self::TITLE,
            'description' => $description,
            'start_date' => \Carbon\Carbon::now()->toDateString(),
            'end_date' => \Carbon\Carbon::now()->addDays(7)->toDateString(),
            'task_type' => 'weekly',
            'repeat_daily' => false,
            'repeat_weekly' => false,
            'store' => $storeKey,
            'priority' => 'high',
            'requires_photo' => false,
            'status' => 'not_started',
            'created_by' => $owner->id ?? $fallbackUserId,
        ]);
        if ($owner) {
            $task->assignees()->sync([$owner->id]);
            \App\PendingAssignmentText::create([
                'weekly_task_id' => $task->id,
                'user_id' => $owner->id,
                'message' => "New task assigned to you: \"{$task->title}\". " . url('/change-run'),
            ]);
        }
        $task->setRelation('owner', $owner);
        return $task;
    }
}
