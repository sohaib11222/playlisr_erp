<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class TestController extends Controller
{
    public function logRequest()
    {
        \Log::info(request()->all());
        return response()->json(['message' => 'Request logged successfully'], 200);
    }
}
