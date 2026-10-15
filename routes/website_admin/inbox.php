<?php

// Website inbox: job applications, contact messages, buy/sell queries, chats,
// and customer returns (Sarah, 2026-10-15). Required inside web.php's auth group.

Route::get('/website-inbox/applications', 'WebsiteAdmin\ApplicationsController@index')->name('wa.inbox.applications');
Route::post('/website-inbox/applications/archive-low/preview', 'WebsiteAdmin\ApplicationsController@archivePreview')->name('wa.inbox.applications.archive_preview');
Route::post('/website-inbox/applications/archive-low', 'WebsiteAdmin\ApplicationsController@archiveLow')->name('wa.inbox.applications.archive_low');
Route::post('/website-inbox/applications/{id}/status', 'WebsiteAdmin\ApplicationsController@status')->name('wa.inbox.applications.status');
Route::post('/website-inbox/applications/{id}/interview', 'WebsiteAdmin\ApplicationsController@interview')->name('wa.inbox.applications.interview');
Route::post('/website-inbox/applications/{id}/score', 'WebsiteAdmin\ApplicationsController@score')->name('wa.inbox.applications.score');
Route::post('/website-inbox/applications/{id}/delete', 'WebsiteAdmin\ApplicationsController@destroy')->name('wa.inbox.applications.delete');

Route::get('/website-inbox/messages', 'WebsiteAdmin\InboxMessagesController@messages')->name('wa.inbox.messages');
Route::post('/website-inbox/messages/test-email', 'WebsiteAdmin\InboxMessagesController@testEmail')->name('wa.inbox.messages.test_email');
Route::post('/website-inbox/messages/{id}/reply', 'WebsiteAdmin\InboxMessagesController@reply')->name('wa.inbox.messages.reply');
Route::post('/website-inbox/messages/{id}/status', 'WebsiteAdmin\InboxMessagesController@status')->name('wa.inbox.messages.status');

Route::get('/website-inbox/buy-sell', 'WebsiteAdmin\InboxMessagesController@buySell')->name('wa.inbox.buy_sell');

Route::get('/website-inbox/chats', 'WebsiteAdmin\ChatsController@index')->name('wa.inbox.chats');
Route::post('/website-inbox/chats/{id}/status', 'WebsiteAdmin\ChatsController@status')->name('wa.inbox.chats.status');
Route::post('/website-inbox/chats/{id}/delete', 'WebsiteAdmin\ChatsController@destroy')->name('wa.inbox.chats.delete');

Route::get('/website-returns', 'WebsiteAdmin\ReturnsController@index')->name('wa.inbox.returns');
Route::get('/website-returns/create', 'WebsiteAdmin\ReturnsController@create')->name('wa.inbox.returns.create');
Route::post('/website-returns/create', 'WebsiteAdmin\ReturnsController@store')->name('wa.inbox.returns.store');
Route::post('/website-returns/{id}/resolve', 'WebsiteAdmin\ReturnsController@resolve')->name('wa.inbox.returns.resolve');
