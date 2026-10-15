<?php

// Website catalog admin in the ERP (Sarah, 2026-10-15): products, metadata,
// images, stock requests, synced products, featured lists, categories,
// collections and website gift cards. Product creation is intentionally not
// here (ERP Add Product + POS sync only).

$waCatalogId = '[A-Za-z0-9_]+';

Route::get('/website-catalog/products', 'WebsiteAdmin\WebsiteProductsController@index')->name('wa.catalog.products');
Route::post('/website-catalog/products/bulk', 'WebsiteAdmin\WebsiteProductsController@bulk')->name('wa.catalog.products.bulk');
Route::post('/website-catalog/products/price-unpublish/preview', 'WebsiteAdmin\WebsiteProductsController@priceUnpublishPreview')->name('wa.catalog.products.priceUnpublish.preview');
Route::post('/website-catalog/products/price-unpublish/run', 'WebsiteAdmin\WebsiteProductsController@priceUnpublishRun')->name('wa.catalog.products.priceUnpublish.run');
Route::post('/website-catalog/products/price-unpublish/undo', 'WebsiteAdmin\WebsiteProductsController@priceUnpublishUndo')->name('wa.catalog.products.priceUnpublish.undo');
Route::get('/website-catalog/products/{id}', 'WebsiteAdmin\WebsiteProductsController@show')->where('id', $waCatalogId)->name('wa.catalog.products.show');
Route::get('/website-catalog/products/{id}/edit', 'WebsiteAdmin\WebsiteProductsController@edit')->where('id', $waCatalogId)->name('wa.catalog.products.edit');
Route::post('/website-catalog/products/{id}/update', 'WebsiteAdmin\WebsiteProductsController@update')->where('id', $waCatalogId)->name('wa.catalog.products.update');
Route::post('/website-catalog/products/{id}/publish', 'WebsiteAdmin\WebsiteProductsController@togglePublish')->where('id', $waCatalogId)->name('wa.catalog.products.publish');
Route::post('/website-catalog/products/{id}/sale', 'WebsiteAdmin\WebsiteProductsController@toggleSale')->where('id', $waCatalogId)->name('wa.catalog.products.sale');
Route::post('/website-catalog/products/{id}/delete', 'WebsiteAdmin\WebsiteProductsController@destroy')->where('id', $waCatalogId)->name('wa.catalog.products.delete');

Route::get('/website-catalog/images', 'WebsiteAdmin\WebsiteProductsController@images')->name('wa.catalog.images');
Route::post('/website-catalog/images/{id}', 'WebsiteAdmin\WebsiteProductsController@uploadImage')->where('id', $waCatalogId)->name('wa.catalog.images.upload');
Route::get('/website-catalog/metadata', 'WebsiteAdmin\WebsiteProductsController@metadata')->name('wa.catalog.metadata');
Route::post('/website-catalog/metadata', 'WebsiteAdmin\WebsiteProductsController@metadataChange')->name('wa.catalog.metadata.change');
Route::get('/website-catalog/stock-requests', 'WebsiteAdmin\WebsiteProductsController@requests')->name('wa.catalog.requests');

Route::get('/website-catalog/synced', 'WebsiteAdmin\WebsiteCatalogController@synced')->name('wa.catalog.synced');
Route::post('/website-catalog/synced/refresh', 'WebsiteAdmin\WebsiteCatalogController@syncedRefresh')->name('wa.catalog.synced.refresh');
Route::post('/website-catalog/synced/toggle', 'WebsiteAdmin\WebsiteCatalogController@syncedToggle')->name('wa.catalog.synced.toggle');

Route::get('/website-catalog/featured', 'WebsiteAdmin\WebsiteCatalogController@featured')->name('wa.catalog.featured');
Route::post('/website-catalog/featured/add', 'WebsiteAdmin\WebsiteCatalogController@featuredAdd')->name('wa.catalog.featured.add');
Route::post('/website-catalog/featured/{id}/order', 'WebsiteAdmin\WebsiteCatalogController@featuredOrder')->where('id', $waCatalogId)->name('wa.catalog.featured.order');
Route::post('/website-catalog/featured/{id}/remove', 'WebsiteAdmin\WebsiteCatalogController@featuredRemove')->where('id', $waCatalogId)->name('wa.catalog.featured.remove');

Route::get('/website-catalog/featured-records', 'WebsiteAdmin\WebsiteCatalogController@records')->name('wa.catalog.records');
Route::post('/website-catalog/featured-records', 'WebsiteAdmin\WebsiteCatalogController@recordsSave')->name('wa.catalog.records.save');

Route::get('/website-catalog/categories', 'WebsiteAdmin\WebsiteCatalogController@categories')->name('wa.catalog.categories');
Route::get('/website-catalog/categories/new', 'WebsiteAdmin\WebsiteCatalogController@categoryCreate')->name('wa.catalog.categories.create');
Route::post('/website-catalog/categories', 'WebsiteAdmin\WebsiteCatalogController@categorySave')->name('wa.catalog.categories.store');
Route::get('/website-catalog/categories/{id}/edit', 'WebsiteAdmin\WebsiteCatalogController@categoryEdit')->where('id', $waCatalogId)->name('wa.catalog.categories.edit');
Route::post('/website-catalog/categories/{id}', 'WebsiteAdmin\WebsiteCatalogController@categorySave')->where('id', $waCatalogId)->name('wa.catalog.categories.update');
Route::post('/website-catalog/categories/{id}/delete', 'WebsiteAdmin\WebsiteCatalogController@categoryDelete')->where('id', $waCatalogId)->name('wa.catalog.categories.delete');

Route::get('/website-catalog/collections', 'WebsiteAdmin\WebsiteCatalogController@collections')->name('wa.catalog.collections');
Route::get('/website-catalog/collections/new', 'WebsiteAdmin\WebsiteCatalogController@collectionCreate')->name('wa.catalog.collections.create');
Route::post('/website-catalog/collections', 'WebsiteAdmin\WebsiteCatalogController@collectionStore')->name('wa.catalog.collections.store');
Route::post('/website-catalog/collections/{id}/delete', 'WebsiteAdmin\WebsiteCatalogController@collectionDelete')->where('id', $waCatalogId)->name('wa.catalog.collections.delete');

Route::get('/website-gift-cards', 'WebsiteAdmin\WebsiteGiftCardsController@index')->name('wa.catalog.giftcards');
Route::get('/website-gift-cards/new', 'WebsiteAdmin\WebsiteGiftCardsController@create')->name('wa.catalog.giftcards.create');
Route::post('/website-gift-cards', 'WebsiteAdmin\WebsiteGiftCardsController@store')->name('wa.catalog.giftcards.store');
Route::get('/website-gift-cards/{id}/edit', 'WebsiteAdmin\WebsiteGiftCardsController@edit')->where('id', $waCatalogId)->name('wa.catalog.giftcards.edit');
Route::post('/website-gift-cards/{id}', 'WebsiteAdmin\WebsiteGiftCardsController@update')->where('id', $waCatalogId)->name('wa.catalog.giftcards.update');
Route::post('/website-gift-cards/{id}/delete', 'WebsiteAdmin\WebsiteGiftCardsController@destroy')->where('id', $waCatalogId)->name('wa.catalog.giftcards.delete');
Route::post('/website-gift-cards/{id}/resend', 'WebsiteAdmin\WebsiteGiftCardsController@resend')->where('id', $waCatalogId)->name('wa.catalog.giftcards.resend');
