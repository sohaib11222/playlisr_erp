<?php

namespace App\Http\Controllers;

use App\Help\Catalog;
use App\HelpSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class HelpController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $sections = Catalog::bySection();
        $results = $q === '' ? [] : Catalog::search($q);

        if ($q !== '') {
            $this->logSearch($request, $q, count($results));
        }

        return view('help.index', compact('sections', 'results', 'q'));
    }

    /**
     * Log the search so the Help Searches report can surface popular queries
     * and zero-result queries (= what the handbook is missing). Best-effort:
     * never let a logging failure break the help page.
     */
    protected function logSearch(Request $request, string $q, int $resultCount): void
    {
        try {
            if (!Schema::hasTable('help_searches')) {
                return;
            }
            HelpSearch::create([
                'business_id'  => (int) $request->session()->get('user.business_id'),
                'user_id'      => optional(auth()->user())->id,
                'query'        => mb_strtolower(mb_substr($q, 0, 191)),
                'result_count' => $resultCount,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('help search log failed', ['err' => $e->getMessage()]);
        }
    }

    public function show($slug)
    {
        $article = Catalog::find($slug);
        if (!$article) {
            abort(404);
        }
        $related = [];
        foreach (Catalog::articles() as $a) {
            if ($a['slug'] !== $slug && ($a['section'] ?? null) === ($article['section'] ?? null)) {
                $related[] = $a;
            }
        }

        return view('help.show', compact('article', 'related'));
    }
}
