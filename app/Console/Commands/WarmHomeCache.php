<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Rebuilds the store-wide /home dashboard caches (MTD/YTD totals, Top
 * Sellers, Fastest Selling Genres, FY sales chart) in the background so
 * "Go to the ERP" never pays the ~10s cold rebuild. Read-only: it renders
 * HomeController@index as an admin and writes nothing but cache entries.
 */
class WarmHomeCache extends Command
{
    protected $signature = 'home:warm-cache {--user= : user id to render as (default: first user of business 1)}';
    protected $description = 'Refresh the cached store-wide sections of the /home dashboard';

    public function handle()
    {
        $t = microtime(true);
        $user = $this->option('user')
            ? \App\User::find($this->option('user'))
            : \App\User::where('business_id', 1)->orderBy('id')->first();
        if (!$user) {
            $this->error('no user');
            return 1;
        }

        $request = \Illuminate\Http\Request::create('/home', 'GET');
        app()->instance('request', $request);
        $session = app('session')->driver();
        $request->setLaravelSession($session);
        $session->start();
        auth()->login($user);
        $business = \App\Business::find($user->business_id);
        $session->put('user', ['id' => $user->id, 'business_id' => $user->business_id]);
        $session->put('business', $business->toArray());
        $session->put('financial_year', app(\App\Utils\BusinessUtil::class)->getCurrentFinancialYear($user->business_id));

        config(['home.cache_refresh' => true]);
        try {
            app(\App\Http\Controllers\HomeController::class)->index();
        } finally {
            config(['home.cache_refresh' => false]);
            auth()->logout();
        }
        $this->info('home cache warmed in ' . round((microtime(true) - $t) * 1000) . 'ms');
        return 0;
    }
}
