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

        return view('admin.help_assistant_settings', [
            'has_key' => $hasKey,
            'masked' => $masked,
            'model' => $cfg['model'] ?? config('services.anthropic.model', 'claude-haiku-4-5'),
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

        Storage::put(self::STORE_PATH, json_encode($cfg, JSON_PRETTY_PRINT));

        return redirect()->action('HelpAssistantSettingsController@index')
            ->with('status', ['success' => 1, 'msg' => 'Help assistant settings saved.']);
    }
}
