<?php

// Warehouse + shipping rates, moved from the website's /admin/warehouse and
// /admin/rates (Sarah, 2026-10-15).
Route::get('/website-admin/warehouse', 'WebsiteAdmin\WarehouseController@index')->name('wa.warehouse.index');
Route::post('/website-admin/warehouse/sync', 'WebsiteAdmin\WarehouseController@sync')->name('wa.warehouse.sync');
Route::get('/website-admin/warehouse/packer', 'WebsiteAdmin\WarehouseController@packer')->name('wa.warehouse.packer');
Route::post('/website-admin/warehouse/pick/{orderId}/{itemId}', 'WebsiteAdmin\WarehouseController@pick')->name('wa.warehouse.pick');
Route::get('/website-admin/warehouse/orders/{id}', 'WebsiteAdmin\WarehouseController@show')->name('wa.warehouse.show');
Route::post('/website-admin/warehouse/orders/{id}/location/{itemId}', 'WebsiteAdmin\WarehouseController@updateLocation')->name('wa.warehouse.location');
Route::post('/website-admin/warehouse/orders/{id}/notes', 'WebsiteAdmin\WarehouseController@saveNotes')->name('wa.warehouse.notes');
Route::post('/website-admin/warehouse/orders/{id}/discogs-status', 'WebsiteAdmin\WarehouseController@discogsStatus')->name('wa.warehouse.discogsStatus');
Route::get('/website-admin/warehouse/orders/{id}/usps-rates', 'WebsiteAdmin\WarehouseController@uspsRates')->name('wa.warehouse.uspsRates');
Route::post('/website-admin/warehouse/orders/{id}/usps-label', 'WebsiteAdmin\WarehouseController@buyLabel')->name('wa.warehouse.uspsLabel');
Route::post('/website-admin/warehouse/orders/{id}/usps-void', 'WebsiteAdmin\WarehouseController@voidLabel')->name('wa.warehouse.uspsVoid');
Route::get('/website-admin/warehouse/website-order/{id}', 'WebsiteAdmin\WarehouseController@websiteOrder')->name('wa.warehouse.websiteOrder');
Route::get('/website-admin/warehouse/analytics', 'WebsiteAdmin\WarehouseController@analytics')->name('wa.warehouse.analytics');
Route::get('/website-admin/warehouse/metrics', 'WebsiteAdmin\WarehouseController@metrics')->name('wa.warehouse.metrics');
Route::get('/website-admin/warehouse/zones', 'WebsiteAdmin\WarehouseController@zonesPage')->name('wa.warehouse.zones');
Route::post('/website-admin/warehouse/zones', 'WebsiteAdmin\WarehouseController@zonesSave')->name('wa.warehouse.zonesSave');

Route::get('/website-admin/warehouse/locations', 'WebsiteAdmin\WarehouseLocationsController@index')->name('wa.warehouse.locations');
Route::get('/website-admin/warehouse/locations/sample.csv', 'WebsiteAdmin\WarehouseLocationsController@sample')->name('wa.warehouse.locationsSample');
Route::post('/website-admin/warehouse/locations/import', 'WebsiteAdmin\WarehouseLocationsController@import')->name('wa.warehouse.locationsImport');
Route::post('/website-admin/warehouse/locations/scan', 'WebsiteAdmin\WarehouseLocationsController@scan')->name('wa.warehouse.locationsScan');
Route::get('/website-admin/warehouse/locations/scan.csv', 'WebsiteAdmin\WarehouseLocationsController@scanExport')->name('wa.warehouse.locationsScanExport');
Route::post('/website-admin/warehouse/locations/auto-create', 'WebsiteAdmin\WarehouseLocationsController@autoCreate')->name('wa.warehouse.locationsAutoCreate');
Route::post('/website-admin/warehouse/locations', 'WebsiteAdmin\WarehouseLocationsController@store')->name('wa.warehouse.locationsStore');
Route::post('/website-admin/warehouse/locations/{id}', 'WebsiteAdmin\WarehouseLocationsController@update')->name('wa.warehouse.locationsUpdate');
Route::post('/website-admin/warehouse/locations/{id}/delete', 'WebsiteAdmin\WarehouseLocationsController@destroy')->name('wa.warehouse.locationsDelete');

Route::get('/website-admin/rates', 'WebsiteAdmin\ShippingRatesController@index')->name('wa.warehouse.rates');
Route::get('/website-admin/rates/add', 'WebsiteAdmin\ShippingRatesController@create')->name('wa.warehouse.ratesAdd');
Route::post('/website-admin/rates', 'WebsiteAdmin\ShippingRatesController@store')->name('wa.warehouse.ratesStore');
Route::post('/website-admin/rates/{id}', 'WebsiteAdmin\ShippingRatesController@update')->name('wa.warehouse.ratesUpdate');
Route::post('/website-admin/rates/{id}/delete', 'WebsiteAdmin\ShippingRatesController@destroy')->name('wa.warehouse.ratesDelete');
