<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

// Diagnostic viewer for product-edit-form saves. The ProductController@update
// path silently rolls back when something inside the try{} throws, and the
// resulting toast on /products is easy to miss — this just dumps the latest
// captured attempts (request fields, result, exception class/message) so we
// can see what's actually breaking on Fatteen's saves without SSH.
class ProductUpdateDebugController extends Controller
{
    public function index()
    {
        $files = collect(Storage::disk('local')->files('product-update-debug'))
            ->filter(function ($f) { return str_ends_with($f, '.json'); })
            ->sort()
            ->reverse()
            ->take(50)
            ->values();

        $entries = [];
        foreach ($files as $f) {
            $raw = Storage::disk('local')->get($f);
            $data = json_decode($raw, true);
            if (!$data) continue;
            $entries[] = $data;
        }

        return view('admin.product_update_debug', ['entries' => $entries]);
    }
}
