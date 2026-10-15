<?php

// Website sync/import tools and the website admin dashboard, moved from
// nivessa.com /admin/dashboard, /admin/sync-dashboard, /admin/auto-sync,
// /admin/pos-image-refresh, /admin/pos-artist-refresh,
// /admin/bulk-discogs-import, /admin/background-import, /admin/import-status
// and /admin/abandoned-carts. The job pages poll through /website-tools/api.
Route::get('/website-tools', 'WebsiteAdmin\WebsiteToolsController@dashboard')->name('wa.tools.dashboard');
Route::get('/website-tools/sync-dashboard', 'WebsiteAdmin\WebsiteToolsController@syncDashboard')->name('wa.tools.syncDashboard');
Route::get('/website-tools/auto-sync', 'WebsiteAdmin\WebsiteToolsController@autoSync')->name('wa.tools.autoSync');
Route::get('/website-tools/pos-image-refresh', 'WebsiteAdmin\WebsiteToolsController@posImageRefresh')->name('wa.tools.posImageRefresh');
Route::get('/website-tools/pos-artist-refresh', 'WebsiteAdmin\WebsiteToolsController@posArtistRefresh')->name('wa.tools.posArtistRefresh');
Route::get('/website-tools/bulk-discogs-import', 'WebsiteAdmin\WebsiteToolsController@bulkDiscogsImport')->name('wa.tools.bulkDiscogsImport');
Route::post('/website-tools/bulk-discogs-import/run', 'WebsiteAdmin\WebsiteToolsController@bulkDiscogsStream')->name('wa.tools.bulkDiscogsStream');
Route::get('/website-tools/background-import', 'WebsiteAdmin\WebsiteToolsController@backgroundImport')->name('wa.tools.backgroundImport');
Route::get('/website-tools/import-status', 'WebsiteAdmin\WebsiteToolsController@importStatus')->name('wa.tools.importStatus');
Route::post('/website-tools/api', 'WebsiteAdmin\WebsiteToolsController@api')->name('wa.tools.api');
Route::get('/website-carts', 'WebsiteAdmin\WebsiteToolsController@abandonedCarts')->name('wa.tools.abandonedCarts');
