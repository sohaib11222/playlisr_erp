<?php

namespace App\Http\Controllers;

use App\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Confirm screen for cashiers whose unclosed shift was auto-closed when
// the next cashier took over the drawer. The prior cashier can't count
// yesterday's drawer (the next cashier has been using it since), so the
// closing_amount is locked to the next cashier's cash-in-hand at open
// (the actual drawer state at handover). The prior cashier just confirms
// and explains why they left without closing.
//
// The cash_registers row has a "[HANDOVER_PENDING]" prefix on closing_note
// that flags it for this flow. On confirm, the prefix is stripped and the
// cashier's reason is appended.
//
// Built 2026-05-13 after Manolo left without closing his 9:33am shift
// and Luis got blocked from opening — we changed to let Luis open and
// route Manolo here on his next /pos/create.
class HandoverConfirmController extends Controller
{
    // Hyphen, not underscore: '_' is a single-char wildcard in MySQL LIKE,
    // which would let unrelated notes match the gate query in
    // SellPosController. Hyphen is a literal character in LIKE patterns.
    const MARKER = '[HANDOVER-PENDING]';

    public function show($id)
    {
        $user_id = auth()->user()->id;
        $reg = CashRegister::where('id', $id)
            ->where('user_id', $user_id)
            ->where('status', 'close')
            ->first();
        if (!$reg) {
            return redirect()->action('SellPosController@create')
                ->with('status', ['success' => 0, 'msg' => 'Handover register not found or not yours.']);
        }
        if (stripos((string) $reg->closing_note, self::MARKER) === false) {
            // Already confirmed — nothing to do.
            return redirect()->action('SellPosController@create')
                ->with('status', ['success' => 1, 'msg' => 'Handover already confirmed.']);
        }

        $locationName = DB::table('business_locations')->where('id', $reg->location_id)->value('name');
        $opened = \Carbon::parse($reg->created_at)->setTimezone('America/Los_Angeles')->format('M j, Y g:i A');
        $closed = $reg->closed_at
            ? \Carbon::parse($reg->closed_at)->setTimezone('America/Los_Angeles')->format('M j, Y g:i A')
            : null;

        return view('cash_register.handover_confirm', [
            'register'      => $reg,
            'location_name' => $locationName ?: 'Unknown store',
            'opened_at'     => $opened,
            'closed_at'     => $closed,
        ]);
    }

    public function confirm(Request $request, $id)
    {
        $user_id = auth()->user()->id;
        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return redirect('/cash-register/handover-confirm/' . $id)
                ->with('status', ['success' => 0, 'msg' => 'Please explain why your shift wasn\'t closed.']);
        }

        $reg = CashRegister::where('id', $id)
            ->where('user_id', $user_id)
            ->where('status', 'close')
            ->first();
        if (!$reg) {
            return redirect()->action('SellPosController@create')
                ->with('status', ['success' => 0, 'msg' => 'Handover register not found.']);
        }

        // Strip the marker prefix and append the cashier's reason.
        $note = (string) $reg->closing_note;
        $note = preg_replace('/^' . preg_quote(self::MARKER, '/') . '\s*/', '', $note);
        $nowLa = \Carbon::now()->setTimezone('America/Los_Angeles')->format('M j, Y g:i A');
        $note .= "\n\nConfirmed by cashier at {$nowLa}. Reason: " . $reason;

        $reg->closing_note = $note;
        $reg->save();

        return redirect()->action('SellPosController@create')
            ->with('status', [
                'success' => 1,
                'msg' => 'Handover confirmed. Continue with your new shift.',
            ]);
    }
}
