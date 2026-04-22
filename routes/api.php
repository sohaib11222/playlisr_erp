<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});



Route::post('test' , function(){
   \Log::info(request()->all());
});

/*
|--------------------------------------------------------------------------
| Nivessa website (jonhedvat/server) bridge
|--------------------------------------------------------------------------
| The website API proxies gift-card and (later) store-credit operations
| here so the ERP stays the single source of truth. All routes are guarded
| by a shared bearer token (see config/services.php: nivessa_web).
*/
Route::prefix('v1/nivessa-web')
    ->middleware('verify.nivessa_web')
    ->group(function () {
        Route::post('gift-cards/lookup', [\App\Http\Controllers\Api\NivessaGiftCardController::class, 'lookup']);
        Route::post('gift-cards/charge', [\App\Http\Controllers\Api\NivessaGiftCardController::class, 'charge']);
        Route::post('gift-cards/issue',  [\App\Http\Controllers\Api\NivessaGiftCardController::class, 'issue']);
    });