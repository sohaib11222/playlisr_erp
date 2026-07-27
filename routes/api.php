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
        Route::post('store-credit/adjust', [\App\Http\Controllers\Api\NivessaStoreCreditController::class, 'adjust']);
        Route::get('products-feed', [\App\Http\Controllers\Api\NivessaProductsFeedController::class, 'index']);
        // On-the-spot POS sales for one LA day, by product + store, so the
        // nivessa.com Event Sales Report can attribute a party's day-of sales
        // to its featured record. Date is a path param (nginx strips query
        // strings on these bridge GETs). Read-only, graceful-empty.
        Route::get('event-onsite-sales/{date}', [\App\Http\Controllers\Api\NivessaProductsFeedController::class, 'eventOnsiteSales']);
    });