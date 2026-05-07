<?php

namespace App\Http\Controllers;

use App\HelpArticle;
use App\HelpSearchLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HelpController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $articles = HelpArticle::published()->orderBy('section')->orderBy('sort_order')->orderBy('title')->get();
        $sections = $articles->groupBy(function ($a) {
            return $a->section ?: 'General';
        });

        $results = collect();
        if ($q !== '') {
            $results = HelpArticle::published()->search($q)->orderBy('sort_order')->orderBy('title')->limit(50)->get();
            $this->logSearchInternal($q, $results->count(), null, $request->get('page_key'));
        }

        return view('help.index', compact('articles', 'sections', 'results', 'q'));
    }

    public function show($slug)
    {
        $article = HelpArticle::published()->where('slug', $slug)->firstOrFail();
        $related = HelpArticle::published()
            ->where('id', '!=', $article->id)
            ->where('section', $article->section)
            ->orderBy('sort_order')->orderBy('title')
            ->limit(8)->get();

        return view('help.show', compact('article', 'related'));
    }

    public function drawer(Request $request)
    {
        $pageKey = trim((string) $request->get('page_key', ''));
        $slug = trim((string) $request->get('slug', ''));

        $article = null;
        if ($slug !== '') {
            $article = HelpArticle::published()->where('slug', $slug)->first();
        }
        if (!$article && $pageKey !== '') {
            $article = HelpArticle::findByPageKey($pageKey);
        }

        $suggestions = collect();
        if (!$article && $pageKey !== '') {
            $prefix = explode('.', $pageKey)[0];
            $suggestions = HelpArticle::published()
                ->where(function ($qq) use ($prefix) {
                    $qq->where('section', 'like', "%{$prefix}%")
                       ->orWhere('title', 'like', "%{$prefix}%");
                })
                ->orderBy('sort_order')->limit(6)->get();
        }

        return view('help.partials.drawer', [
            'article' => $article,
            'pageKey' => $pageKey,
            'suggestions' => $suggestions,
        ]);
    }

    public function searchAjax(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = HelpArticle::published()->search($q)
            ->orderBy('sort_order')->orderBy('title')
            ->limit(12)
            ->get(['id', 'slug', 'title', 'section', 'summary']);

        $this->logSearchInternal($q, $results->count(), null, $request->get('page_key'));

        return response()->json([
            'results' => $results->map(function ($a) {
                return [
                    'slug' => $a->slug,
                    'title' => $a->title,
                    'section' => $a->section,
                    'summary' => $a->summary,
                    'url' => route('help.show', $a->slug),
                ];
            }),
        ]);
    }

    public function logClick(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $slug = trim((string) $request->get('slug', ''));
        if ($q === '' || $slug === '') {
            return response()->json(['ok' => false]);
        }

        try {
            HelpSearchLog::where('user_id', auth()->id())
                ->where('query', $q)
                ->whereNull('clicked_slug')
                ->latest('id')
                ->limit(1)
                ->update(['clicked_slug' => $slug]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false]);
        }

        return response()->json(['ok' => true]);
    }

    public function adminReport(Request $request)
    {
        if (!auth()->user() || !auth()->user()->can('user.view')) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = $request->session()->get('user.business_id');
        $days = (int) $request->get('days', 30);
        $days = max(1, min(365, $days));
        $since = now()->subDays($days);

        $top = HelpSearchLog::where('business_id', $businessId)
            ->where('created_at', '>=', $since)
            ->select('query', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) as zero_hits'), DB::raw('SUM(CASE WHEN clicked_slug IS NOT NULL THEN 1 ELSE 0 END) as clicks'))
            ->groupBy('query')
            ->orderByDesc('cnt')
            ->limit(100)
            ->get();

        $zeroResult = HelpSearchLog::where('business_id', $businessId)
            ->where('created_at', '>=', $since)
            ->where('result_count', 0)
            ->select('query', DB::raw('COUNT(*) as cnt'))
            ->groupBy('query')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        $totalSearches = HelpSearchLog::where('business_id', $businessId)
            ->where('created_at', '>=', $since)->count();

        $uniqueUsers = HelpSearchLog::where('business_id', $businessId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->distinct('user_id')->count('user_id');

        return view('help.admin_report', compact('top', 'zeroResult', 'totalSearches', 'uniqueUsers', 'days'));
    }

    protected function logSearchInternal($query, $resultCount, $clickedSlug = null, $pageKey = null)
    {
        try {
            HelpSearchLog::create([
                'business_id' => session('user.business_id'),
                'user_id' => auth()->id(),
                'query' => mb_substr($query, 0, 250),
                'result_count' => $resultCount,
                'clicked_slug' => $clickedSlug,
                'page_key' => $pageKey ? mb_substr($pageKey, 0, 100) : null,
            ]);
        } catch (\Throwable $e) {
            // never let logging crash a help request
        }
    }
}
