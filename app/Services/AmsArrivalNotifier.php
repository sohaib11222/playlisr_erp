<?php

namespace App\Services;

use App\Contact;
use App\CustomerPickup;
use App\Services\AmsPickupOrders;
use App\Services\AmsPurchaseOrders;

/**
 * Notifies the customer behind an AMS special order when their item lands.
 *
 * Two entry points:
 *   - notifyCustomer(): send one pickup's arrival notice on the requested
 *     channels (used by the manual "Arrived" button).
 *   - handlePurchaseReceived(): the automatic path — when a Purchase is
 *     marked received, match its AMS order number to any still-inbound
 *     pickups and flip + notify them, no human click needed.
 *
 * Everything here is best-effort and defensive: a missing email/phone or a
 * failed send is reported, never thrown, so it can never break the purchase
 * save or the POS sell flow.
 */
class AmsArrivalNotifier
{
    /** Human label for a pickup's item, e.g. "Artist — Title". */
    public static function pickupLabel(CustomerPickup $pickup): string
    {
        $artist = trim((string) optional($pickup->product)->artist);
        $name = trim((string) optional($pickup->product)->name);
        $label = trim(implode(' — ', array_filter([$artist, $name])));
        if ($label === '') {
            $label = trim((string) optional($pickup->variation)->sub_sku);
        }
        return $label;
    }

    /**
     * Send arrival notices for one pickup on the given channels
     * (['email'], ['sms'], or both). Returns ['email' => ['ok'=>.., 'msg'=>..], ...].
     */
    public static function notifyCustomer(CustomerPickup $pickup, array $channels): array
    {
        $results = [];
        foreach ($channels as $ch) {
            $results[$ch] = self::sendOne($ch, $pickup);
        }
        return $results;
    }

    private static function sendOne(string $channel, CustomerPickup $pickup): array
    {
        $contact = $pickup->contact;
        if (!$contact) {
            return ['ok' => false, 'msg' => 'No contact linked to this pickup.'];
        }

        // Fall back to the email/phone captured on the pickup form when the
        // contact record itself doesn't have one.
        $meta = AmsPickupOrders::get((int) $pickup->business_id, (int) $pickup->id) ?: [];
        $label = self::pickupLabel($pickup);
        $storeName = optional($pickup->location)->name ?: 'Nivessa';

        if ($channel === 'email') {
            $email = $contact->email ?: ($meta['ams_email'] ?? null);
            if (empty($email)) {
                return ['ok' => false, 'msg' => 'Customer has no email on file.'];
            }
            $r = \App\Services\WantNotice::email($email, 'special', trim((string) ($contact->first_name ?? '')),
                \App\Services\WantNotice::store($storeName),
                ['artist' => trim((string) optional($pickup->product)->artist), 'title' => trim((string) optional($pickup->product)->name) ?: 'Your special order',
                 'image' => (string) (optional($pickup->product)->image_url ?? '')]);
            if (!$r['ok']) \Log::warning('CustomerPickup email failed: ' . $r['msg']);
            return $r;
        }

        if ($channel === 'sms') {
            $phone = $contact->mobile ?: ($contact->alternate_number ?: ($meta['ams_phone'] ?? null));
            if (empty($phone)) {
                return ['ok' => false, 'msg' => 'Customer has no phone on file.'];
            }
            $first = trim((string) ($contact->first_name ?? ''));
            $artist = trim((string) optional($pickup->product)->artist);
            $name = trim((string) optional($pickup->product)->name);
            $label = $name !== '' ? $name . ($artist !== '' ? ' by ' . $artist : '') : '';
            $msg = \App\Services\WantNotice::pickupText('special', $first, $label, \App\Services\WantNotice::store($storeName));
            try {
                // Send from the store's own Quo line, like the other ERP texts.
                $sms = app(\App\Services\OpenPhoneService::class);
                $result = $sms->sendFromStore(optional($pickup->location)->name, $phone, $msg);
                $ok = (bool) ($result['success'] ?? false);
                if (!$ok) {
                    \Log::warning('CustomerPickup sms failed for pickup #' . $pickup->id . ': ' . ($result['msg'] ?? ''));
                }
                return ['ok' => $ok, 'msg' => $result['msg'] ?? ''];
            } catch (\Throwable $e) {
                \Log::warning('CustomerPickup sms failed: ' . $e->getMessage());
                return ['ok' => false, 'msg' => 'Text failed: ' . $e->getMessage()];
            }
        }

        return ['ok' => false, 'msg' => 'Unknown notify channel: ' . $channel];
    }

    /**
     * Automatic path: a Purchase just became 'received'. If an AMS order
     * number was recorded on that purchase, find every still-inbound pickup
     * tagged with the same number, mark it arrived, and notify the customer
     * by whatever channels they have on file (email + text).
     *
     * Returns the number of pickups notified. NEVER throws — wraps everything
     * so a notify hiccup can't roll back the purchase.
     */
    public static function handlePurchaseReceived(int $business_id, $transaction): int
    {
        try {
            if (!$transaction || (int) ($transaction->id ?? 0) <= 0) {
                return 0;
            }
            $amsNumber = AmsPurchaseOrders::get($business_id, (int) $transaction->id);
            if ($amsNumber === null || trim($amsNumber) === '') {
                return 0;
            }

            $pickupIds = AmsPickupOrders::onOrderIdsByAmsNumber($business_id, $amsNumber);
            if (empty($pickupIds)) {
                return 0;
            }

            $count = 0;
            $pickups = CustomerPickup::where('business_id', $business_id)
                ->whereIn('id', $pickupIds)
                ->with(['contact', 'product', 'variation', 'location'])
                ->get();

            foreach ($pickups as $pickup) {
                // Flip to in-hand: the pickup row already carries status 'ready'.
                AmsPickupOrders::put($business_id, $pickup->id, [
                    'on_order' => false,
                    'arrived_at' => now()->toDateTimeString(),
                    'received_purchase_id' => (int) $transaction->id,
                ]);

                // Notify on every channel the customer actually has — from the
                // contact record, or the email/phone captured on the pickup form.
                $meta = AmsPickupOrders::get($business_id, (int) $pickup->id) ?: [];
                $channels = [];
                $contact = $pickup->contact;
                if (($contact && !empty($contact->email)) || !empty($meta['ams_email'])) $channels[] = 'email';
                if (($contact && ($contact->mobile || $contact->alternate_number)) || !empty($meta['ams_phone'])) $channels[] = 'sms';
                if (!empty($channels)) {
                    // Only mark notified when at least one channel actually
                    // went out, so a failed send still shows as not notified
                    // and staff can hit "Arrived" to retry.
                    $results = self::notifyCustomer($pickup, $channels);
                    $anyOk = false;
                    foreach ($results as $ch => $r) {
                        if (!empty($r['ok'])) {
                            $anyOk = true;
                        } else {
                            \Log::warning("AMS arrival: {$ch} notify failed for pickup #{$pickup->id}: " . ($r['msg'] ?? ''));
                        }
                    }
                    if ($anyOk) {
                        AmsPickupOrders::put($business_id, $pickup->id, ['notified' => true]);
                    }
                }
                $count++;
            }

            if ($count > 0) {
                \Log::info("AMS arrival: purchase #{$transaction->id} (AMS {$amsNumber}) notified {$count} customer pickup(s).");
            }
            return $count;
        } catch (\Throwable $e) {
            \Log::warning('AMS handlePurchaseReceived failed: ' . $e->getMessage());
            return 0;
        }
    }
}
