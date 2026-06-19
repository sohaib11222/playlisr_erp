<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Self-serve knowledge editor. Managers add titled entries here and they feed
 * BOTH the "Ask the ERP" bot AND the Help & Handbook (/help) pages — instantly,
 * with no code change, deploy, or worker recycle. Entries live in a JSON file
 * (storage/app, same no-migration pattern as the other admin tools) and are
 * read fresh on every request, so a save is live immediately.
 *
 * Catalog::articles() merges these in (via forCatalog()) so they show up on the
 * /help index, search, and article pages; the bot's handbook section iterates
 * the same Catalog, so the bot picks them up too — one source, both surfaces.
 */
class HelpKnowledgeController extends Controller
{
    const STORE_PATH = 'help_custom_articles.json';

    private function guard()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public static function readAll()
    {
        if (!Storage::exists(self::STORE_PATH)) {
            return [];
        }
        $data = json_decode(Storage::get(self::STORE_PATH), true);

        return is_array($data) ? $data : [];
    }

    private static function writeAll(array $items)
    {
        Storage::put(self::STORE_PATH, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function index()
    {
        $this->guard();

        $entries = self::readAll();
        // Newest first for the list.
        usort($entries, function ($a, $b) {
            return ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '');
        });

        $edit = null;
        if (request()->filled('edit')) {
            foreach ($entries as $e) {
                if (($e['id'] ?? '') === request('edit')) {
                    $edit = $e;
                    break;
                }
            }
        }

        return view('admin.help_knowledge', ['entries' => $entries, 'edit' => $edit]);
    }

    public function save(Request $request)
    {
        $this->guard();

        $title = trim((string) $request->input('title', ''));
        $section = trim((string) $request->input('section', '')) ?: 'Store Notes';
        $body = trim((string) $request->input('body', ''));
        $id = trim((string) $request->input('id', ''));

        if ($title === '' || $body === '') {
            return redirect()->action('HelpKnowledgeController@index')
                ->with('status', ['success' => 0, 'msg' => 'Title and details are both required.']);
        }

        $items = self::readAll();
        $now = now()->toDateTimeString();

        if ($id !== '') {
            // Update existing.
            $found = false;
            foreach ($items as &$e) {
                if (($e['id'] ?? '') === $id) {
                    $e['title'] = $title;
                    $e['section'] = $section;
                    $e['body'] = $body;
                    $e['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($e);
            if (!$found) {
                $id = '';
            }
        }

        if ($id === '') {
            $items[] = [
                'id' => 'note-' . uniqid(),
                'title' => $title,
                'section' => $section,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        self::writeAll($items);

        return redirect()->action('HelpKnowledgeController@index')
            ->with('status', ['success' => 1, 'msg' => 'Saved. It is live in the bot and on the Help pages now.']);
    }

    public function delete(Request $request)
    {
        $this->guard();

        $id = trim((string) $request->input('id', ''));
        $items = array_values(array_filter(self::readAll(), function ($e) use ($id) {
            return ($e['id'] ?? '') !== $id;
        }));
        self::writeAll($items);

        return redirect()->action('HelpKnowledgeController@index')
            ->with('status', ['success' => 1, 'msg' => 'Entry removed.']);
    }

    /**
     * Map the saved entries into the same shape App\Help\Catalog uses, so they
     * merge into /help and the bot's handbook with no extra wiring. Best-effort:
     * never let a bad file break the Catalog.
     */
    public static function forCatalog()
    {
        $out = [];
        try {
            foreach (self::readAll() as $e) {
                $body = (string) ($e['body'] ?? '');
                if (trim($body) === '') {
                    continue;
                }
                $title = $e['title'] ?? 'Store Note';
                $summary = mb_substr(preg_replace('/\s+/', ' ', $body), 0, 140);
                $out[] = [
                    'slug' => $e['id'] ?? ('note-' . md5($title)),
                    'title' => $title,
                    'section' => $e['section'] ?? 'Store Notes',
                    'sort' => 100,
                    'summary' => $summary,
                    'page_keys' => [],
                    'body_html' => '<p>' . nl2br(e($body)) . '</p>',
                ];
            }
        } catch (\Throwable $ex) {
            \Log::warning('HelpKnowledge forCatalog failed: ' . $ex->getMessage());
        }

        return $out;
    }
}
