<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lets an admin set the Claude API key for the help assistant from inside the
 * ERP, so nobody has to SSH in or hand-edit the server .env. The key is stored
 * in storage/app/help_assistant.json (outside the repo, same no-migration
 * pattern as the other admin tools). HelpAssistantController reads it from here.
 */
class HelpAssistantSettingsController extends Controller
{
    const STORE_PATH = 'help_assistant.json';

    // Fill-in-the-blank starter doc. Managers edit this so the bot can answer
    // the real "how do we run the store" questions employees ask. Anything left
    // as [FILL IN ...] tells the bot to say it doesn't have that detail yet.
    const DEFAULT_KNOWLEDGE = <<<'TXT'
=== NIVESSA STORE OPERATIONS — managers can edit this anytime at /admin/help-assistant ===
Replace every [FILL IN ...] with the real answer. Leave a line blank/deleted if it doesn't apply.

LISTENING PARTIES
- Where the listening-party merch is kept: [FILL IN — e.g. back storage room, shelf labeled ...]
- Where the music/playlist for the listening party lives: [FILL IN — e.g. Spotify account "nivessa", playlist named after the event; ask a manager for the login]

SUPPLIES (waters, bags, receipt paper, labels, cleaning kits, etc.)
- Where extra supplies are stored: [FILL IN — location]
- How to order more supplies: [FILL IN — who places the order and how, e.g. text the manager, order through ___]
- When supply shipments usually arrive: [FILL IN — schedule / lead time]
- What to do when we run out of something (e.g. waters): [FILL IN — who to tell and how to flag it]

REFUNDS / RETURNS (in the ERP)
- Refunds are manager-approved. In the ERP: open Sell > List Sales (or List POS), find the sale, open its Actions menu and choose "Sell Return". Set the quantity/amount to return and save — a manager sign-off is required. The refund prints a return receipt.
- [FILL IN any store-specific refund rules — e.g. receipt required, time limit, cash vs store credit]

NEW LABELS
- How to use the new labels: [FILL IN — steps, which printer, which template]

RECEIPT / LABEL PRINTER
- How to change or reload the paper roll: [FILL IN — steps for your printer model; which way the roll faces]
- If the printer is broken / not printing: [FILL IN — restart steps, check cable/power, who to call if it stays down]

SPOTIFY
- If Spotify won't open: [FILL IN — restart the app/device, the login to use, the backup device or playlist]

EVENTS / PERFORMERS
- Where to see who is performing and when: [FILL IN — the schedule/calendar location]
- When performers should arrive and when they should wrap up / leave: [FILL IN]
- Event rules staff should know: [FILL IN]
TXT;

    private function guard()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public static function readConfig()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);
        return is_array($data) ? $data : [];
    }

    public function index()
    {
        $this->guard();
        $cfg = self::readConfig();
        $hasKey = !empty($cfg['api_key']);
        // Never echo the full key back; show only a masked hint.
        $masked = $hasKey ? '••••••••' . substr($cfg['api_key'], -4) : '';

        // Seed the editable doc with the starter template the first time.
        $knowledge = array_key_exists('store_knowledge', $cfg)
            ? $cfg['store_knowledge']
            : self::DEFAULT_KNOWLEDGE;

        return view('admin.help_assistant_settings', [
            'has_key' => $hasKey,
            'masked' => $masked,
            'model' => $cfg['model'] ?? config('services.anthropic.model', 'claude-haiku-4-5'),
            'store_knowledge' => $knowledge,
        ]);
    }

    public function save(Request $request)
    {
        $this->guard();

        $cfg = self::readConfig();
        $key = trim((string) $request->input('api_key', ''));
        $model = trim((string) $request->input('model', ''));

        // Blank key field = leave the existing key untouched (so saving the
        // model alone doesn't wipe the key). "remove" checkbox clears it.
        if ($request->input('remove_key')) {
            unset($cfg['api_key']);
        } elseif ($key !== '') {
            $cfg['api_key'] = $key;
        }

        $cfg['model'] = $model !== '' ? $model : 'claude-haiku-4-5';

        // Store-operations knowledge the bot answers from. Always present in the
        // form, so save it as-is (trimmed).
        $cfg['store_knowledge'] = trim((string) $request->input('store_knowledge', ''));

        Storage::put(self::STORE_PATH, json_encode($cfg, JSON_PRETTY_PRINT));

        return redirect()->action('HelpAssistantSettingsController@index')
            ->with('status', ['success' => 1, 'msg' => 'Help assistant settings saved.']);
    }
}
