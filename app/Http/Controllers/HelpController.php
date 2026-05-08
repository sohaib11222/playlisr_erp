<?php

namespace App\Http\Controllers;

use App\Help\Catalog;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $sections = Catalog::bySection();
        $results = $q === '' ? [] : Catalog::search($q);

        return view('help.index', compact('sections', 'results', 'q'));
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
