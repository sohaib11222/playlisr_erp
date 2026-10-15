<?php

// Website content, settings and accounts moved from nivessa.com/admin
// (Sarah, 2026-10-15). Required inside web.php's authenticated group.

// Newsletter
Route::get('/website-content/newsletter', 'WebsiteAdmin\NewsletterController@index')->name('wa.content.newsletter');
Route::post('/website-content/newsletter/settings', 'WebsiteAdmin\NewsletterController@saveSettings')->name('wa.content.newsletterSettings');
Route::post('/website-content/newsletter/build', 'WebsiteAdmin\NewsletterController@build')->name('wa.content.newsletterBuild');
Route::get('/website-content/newsletter-candidates', 'WebsiteAdmin\NewsletterController@candidates')->name('wa.content.newsletterCandidates');
Route::get('/website-content/newsletter/{id}', 'WebsiteAdmin\NewsletterController@show')->name('wa.content.newsletterShow');
Route::post('/website-content/newsletter/{id}', 'WebsiteAdmin\NewsletterController@update')->name('wa.content.newsletterUpdate');
Route::post('/website-content/newsletter/{id}/delete', 'WebsiteAdmin\NewsletterController@destroy')->name('wa.content.newsletterDelete');
Route::post('/website-content/newsletter/{id}/test', 'WebsiteAdmin\NewsletterController@test')->name('wa.content.newsletterTest');
Route::post('/website-content/newsletter/{id}/send', 'WebsiteAdmin\NewsletterController@send')->name('wa.content.newsletterSend');
Route::post('/website-content/newsletter/{id}/skip', 'WebsiteAdmin\NewsletterController@skip')->name('wa.content.newsletterSkip');

// Subscribers
Route::get('/website-content/subscriber-list', 'WebsiteAdmin\SubscribersController@subscriberList')->name('wa.content.subscriberList');
Route::get('/website-content/email-subscribers', 'WebsiteAdmin\SubscribersController@index')->name('wa.content.emailSubscribers');
Route::post('/website-content/email-subscribers/sync', 'WebsiteAdmin\SubscribersController@sync')->name('wa.content.emailSubscribersSync');
Route::post('/website-content/email-subscribers/import', 'WebsiteAdmin\SubscribersController@import')->name('wa.content.emailSubscribersImport');
Route::get('/website-content/email-subscribers/export', 'WebsiteAdmin\SubscribersController@export')->name('wa.content.emailSubscribersExport');
Route::post('/website-content/email-subscribers/preview', 'WebsiteAdmin\SubscribersController@preview')->name('wa.content.emailSubscribersPreview');
Route::post('/website-content/email-subscribers/send', 'WebsiteAdmin\SubscribersController@send')->name('wa.content.emailSubscribersSend');

// Blog
Route::get('/website-content/blog', 'WebsiteAdmin\BlogController@index')->name('wa.content.blog');
Route::get('/website-content/blog/create', 'WebsiteAdmin\BlogController@create')->name('wa.content.blogCreate');
Route::post('/website-content/blog', 'WebsiteAdmin\BlogController@store')->name('wa.content.blogStore');
Route::get('/website-content/blog/{id}/edit', 'WebsiteAdmin\BlogController@edit')->name('wa.content.blogEdit');
Route::post('/website-content/blog/{id}', 'WebsiteAdmin\BlogController@update')->name('wa.content.blogUpdate');
Route::post('/website-content/blog/{id}/publish', 'WebsiteAdmin\BlogController@togglePublish')->name('wa.content.blogPublish');
Route::post('/website-content/blog/{id}/delete', 'WebsiteAdmin\BlogController@destroy')->name('wa.content.blogDelete');
Route::post('/website-content/blog-image', 'WebsiteAdmin\BlogController@uploadImage')->name('wa.content.blogImage');
Route::get('/website-content/blog-categories', 'WebsiteAdmin\BlogController@categories')->name('wa.content.blogCategories');
Route::post('/website-content/blog-categories', 'WebsiteAdmin\BlogController@categoryStore')->name('wa.content.blogCategoryStore');
Route::post('/website-content/blog-categories/{id}', 'WebsiteAdmin\BlogController@categoryUpdate')->name('wa.content.blogCategoryUpdate');
Route::post('/website-content/blog-categories/{id}/delete', 'WebsiteAdmin\BlogController@categoryDestroy')->name('wa.content.blogCategoryDelete');

// CMS pages
Route::get('/website-content/pages', 'WebsiteAdmin\PagesController@index')->name('wa.content.pages');
Route::get('/website-content/pages/new', 'WebsiteAdmin\PagesController@create')->name('wa.content.pagesCreate');
Route::post('/website-content/pages', 'WebsiteAdmin\PagesController@store')->name('wa.content.pagesStore');
Route::get('/website-content/pages/{id}/edit', 'WebsiteAdmin\PagesController@edit')->name('wa.content.pagesEdit');
Route::post('/website-content/pages/{id}', 'WebsiteAdmin\PagesController@update')->name('wa.content.pagesUpdate');
Route::post('/website-content/pages/{id}/status', 'WebsiteAdmin\PagesController@toggleStatus')->name('wa.content.pagesStatus');
Route::post('/website-content/pages/{id}/duplicate', 'WebsiteAdmin\PagesController@duplicate')->name('wa.content.pagesDuplicate');
Route::post('/website-content/pages/{id}/delete', 'WebsiteAdmin\PagesController@destroy')->name('wa.content.pagesDelete');
Route::post('/website-content/pages-sync-categories', 'WebsiteAdmin\PagesController@syncCategories')->name('wa.content.pagesSync');
Route::post('/website-content/upload-image', 'WebsiteAdmin\PagesController@uploadImage')->name('wa.content.uploadImage');

// SEO
Route::get('/website-content/seo', 'WebsiteAdmin\SeoController@index')->name('wa.content.seo');
Route::post('/website-content/seo', 'WebsiteAdmin\SeoController@store')->name('wa.content.seoStore');
Route::post('/website-content/seo-defaults', 'WebsiteAdmin\SeoController@defaults')->name('wa.content.seoDefaults');
Route::post('/website-content/seo-sync', 'WebsiteAdmin\SeoController@sync')->name('wa.content.seoSync');
Route::post('/website-content/seo/{id}', 'WebsiteAdmin\SeoController@update')->name('wa.content.seoUpdate');
Route::post('/website-content/seo/{id}/delete', 'WebsiteAdmin\SeoController@destroy')->name('wa.content.seoDelete');

// Search and demand reports
Route::get('/website-content/most-searched', 'WebsiteAdmin\SearchInsightsController@mostSearched')->name('wa.content.mostSearched');
Route::post('/website-content/most-searched/boost', 'WebsiteAdmin\SearchInsightsController@boost')->name('wa.content.mostSearchedBoost');
Route::post('/website-content/most-searched/unboost', 'WebsiteAdmin\SearchInsightsController@unboost')->name('wa.content.mostSearchedUnboost');
Route::get('/website-content/bst-search-queries', 'WebsiteAdmin\SearchInsightsController@bstQueries')->name('wa.content.bstSearchQueries');
Route::get('/website-content/sealed-velocity', 'WebsiteAdmin\SearchInsightsController@sealedVelocity')->name('wa.content.sealedVelocity');

// Buy/Sell/Trade offer items
Route::get('/website-content/bst-offer-items', 'WebsiteAdmin\BstOfferItemsController@index')->name('wa.content.bstOfferItems');
Route::post('/website-content/bst-offer-items', 'WebsiteAdmin\BstOfferItemsController@store')->name('wa.content.bstOfferItemsStore');
Route::post('/website-content/bst-offer-items/{id}', 'WebsiteAdmin\BstOfferItemsController@update')->name('wa.content.bstOfferItemsUpdate');
Route::post('/website-content/bst-offer-items/{id}/toggle', 'WebsiteAdmin\BstOfferItemsController@toggle')->name('wa.content.bstOfferItemsToggle');
Route::post('/website-content/bst-offer-items/{id}/delete', 'WebsiteAdmin\BstOfferItemsController@destroy')->name('wa.content.bstOfferItemsDelete');

// Site settings (admin only)
Route::get('/website-settings', 'WebsiteAdmin\SiteSettingsController@index')->name('wa.content.siteSettings');
Route::post('/website-settings/{type}', 'WebsiteAdmin\SiteSettingsController@save')->name('wa.content.siteSettingsSave');

// Website accounts: customers (staff) and admin logins (admin only)
Route::get('/website-users', 'WebsiteAdmin\WebsiteUsersController@index')->name('wa.content.users');
Route::get('/website-users/customers/{id}', 'WebsiteAdmin\WebsiteUsersController@show')->name('wa.content.usersShow');
Route::get('/website-users/admins', 'WebsiteAdmin\WebsiteUsersController@admins')->name('wa.content.admins');
Route::get('/website-users/admins/add', 'WebsiteAdmin\WebsiteUsersController@adminCreate')->name('wa.content.adminsCreate');
Route::post('/website-users/admins', 'WebsiteAdmin\WebsiteUsersController@adminStore')->name('wa.content.adminsStore');
Route::get('/website-users/admins/{id}/edit', 'WebsiteAdmin\WebsiteUsersController@adminEdit')->name('wa.content.adminsEdit');
Route::post('/website-users/admins/{id}', 'WebsiteAdmin\WebsiteUsersController@adminUpdate')->name('wa.content.adminsUpdate');
Route::post('/website-users/admins/{id}/delete', 'WebsiteAdmin\WebsiteUsersController@adminDestroy')->name('wa.content.adminsDelete');
