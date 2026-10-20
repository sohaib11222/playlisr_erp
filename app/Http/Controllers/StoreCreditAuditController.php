<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Services\WebsiteBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only audit of the two website store credit bugs fixed 2026-10-20
 * (Sarah asked who was affected). Changes nothing.
 *  1. Purchased gift cards used online also took that amount off the
 *     customer's store credit (synced here as gift_card_redeemed_on_backend).
 *  2. Return / refund credit codes weren't lowered when the credit was spent
 *     in store, so the same credit could be spent again online.
 */
class StoreCreditAuditController extends Controller
{
    public function index(Request $request)
    {
        if (!auth()->user()->can('customer.view')) {
            abort(403, 'Unauthorized action.');
        }
        $biz = (int) $request->session()->get('user.business_id');
        $resp = WebsiteBridge::erp('GET', '/store-credit-audit', null, 90);
        $data = $resp['data'] ?? null;
        if (!is_array($data)) {
            return view('report.store_credit_audit', ['error' => $resp['message'] ?? 'Could not reach the website.', 'wronglyTaken' => [], 'maybeTwice' => [], 'exposure' => [], 'okCount' => 0]);
        }

        $hasNotes = Schema::hasColumn('contacts', 'balance_notes');
        $contacts = [];
        $contactFor = function ($email) use (&$contacts, $biz) {
            $email = strtolower(trim((string) $email));
            if ($email === '') return null;
            if (!array_key_exists($email, $contacts)) {
                $contacts[$email] = Contact::where('business_id', $biz)->whereIn('type', ['customer', 'both'])
                    ->whereRaw('LOWER(email) = ?', [$email])->first();
            }
            return $contacts[$email];
        };
        // Each "synced -$X from backend ... gift_card_redeemed_on_backend" note
        // can only match one website redemption.
        $notesLeft = [];
        $takeNote = function ($contact, $amount) use (&$notesLeft, $hasNotes) {
            if (!$contact || !$hasNotes) return null;
            $key = $contact->id;
            if (!isset($notesLeft[$key])) {
                $notesLeft[$key] = [];
                foreach (preg_split('/\r?\n/', (string) $contact->balance_notes) as $line) {
                    if (strpos($line, 'gift_card_redeemed_on_backend') === false) continue;
                    if (preg_match('/^\[([^\]]+)\]\s+synced\s+-\$([\d,]+\.\d{2})/', $line, $m)) {
                        $notesLeft[$key][] = ['at' => $m[1], 'amount' => (float) str_replace(',', '', $m[2])];
                    }
                }
            }
            foreach ($notesLeft[$key] as $i => $n) {
                if (abs($n['amount'] - $amount) < 0.01) {
                    unset($notesLeft[$key][$i]);
                    return $n;
                }
            }
            return null;
        };

        $wronglyTaken = [];
        $maybeTwice = [];
        $okCount = 0;
        $reds = $data['redemptions'] ?? [];
        usort($reds, function ($a, $b) { return strcmp((string) $a['date'], (string) $b['date']); });
        foreach ($reds as $r) {
            $contact = $contactFor($r['email'] ?? '');
            $note = $takeNote($contact, (float) ($r['amount'] ?? 0));
            $row = $r + ['contact' => $contact, 'note' => $note];
            if (empty($r['accountCredit'])) {
                if ($note) $wronglyTaken[] = $row; else $okCount++;
            } else {
                if ($note) $okCount++; else $maybeTwice[] = $row;
            }
        }

        $exposure = [];
        foreach ($data['openCodes'] ?? [] as $o) {
            $contact = $contactFor($o['email'] ?? '');
            $erpBalance = $contact ? (float) $contact->balance : null;
            $limit = $erpBalance === null ? (float) ($o['websiteBalance'] ?? 0) : min($erpBalance, (float) ($o['websiteBalance'] ?? 0));
            if ((float) $o['total'] > $limit + 0.005) {
                $exposure[] = $o + ['contact' => $contact, 'erpBalance' => $erpBalance, 'over' => round((float) $o['total'] - $limit, 2)];
            }
        }

        return view('report.store_credit_audit', [
            'error' => null,
            'wronglyTaken' => $wronglyTaken,
            'maybeTwice' => $maybeTwice,
            'exposure' => $exposure,
            'okCount' => $okCount,
            'hasNotes' => $hasNotes,
        ]);
    }
}
