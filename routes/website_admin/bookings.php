<?php

// Venue bookings + venues, moved from nivessa.com /admin/bookings,
// /admin/bookings/calendar, /admin/add-booking and /admin/venues.
// Prefix is /website-bookings because /bookings is UltimatePOS table bookings.
Route::get('/website-bookings', 'WebsiteAdmin\WebsiteBookingsController@index')->name('wa.bookings.index');
Route::get('/website-bookings/calendar', 'WebsiteAdmin\WebsiteBookingsController@calendar')->name('wa.bookings.calendar');
Route::get('/website-bookings/add', 'WebsiteAdmin\WebsiteBookingsController@addForm')->name('wa.bookings.add');
Route::post('/website-bookings/add', 'WebsiteAdmin\WebsiteBookingsController@addStore')->name('wa.bookings.addStore');

Route::get('/website-bookings/venues', 'WebsiteAdmin\WebsiteBookingsController@venues')->name('wa.bookings.venues');
Route::get('/website-bookings/venues/create', 'WebsiteAdmin\WebsiteBookingsController@venueCreate')->name('wa.bookings.venueCreate');
Route::post('/website-bookings/venues/image', 'WebsiteAdmin\WebsiteBookingsController@venueImageUpload')->name('wa.bookings.venueImageUpload');
Route::post('/website-bookings/venues/image/delete', 'WebsiteAdmin\WebsiteBookingsController@venueImageDelete')->name('wa.bookings.venueImageDelete');
Route::post('/website-bookings/venues', 'WebsiteAdmin\WebsiteBookingsController@venueStore')->name('wa.bookings.venueStore');
Route::get('/website-bookings/venues/{id}/edit', 'WebsiteAdmin\WebsiteBookingsController@venueEdit')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.venueEdit');
Route::post('/website-bookings/venues/{id}', 'WebsiteAdmin\WebsiteBookingsController@venueUpdate')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.venueUpdate');
Route::post('/website-bookings/venues/{id}/delete', 'WebsiteAdmin\WebsiteBookingsController@venueDelete')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.venueDelete');

Route::post('/website-bookings/{id}/status', 'WebsiteAdmin\WebsiteBookingsController@status')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.status');
Route::post('/website-bookings/{id}/edit', 'WebsiteAdmin\WebsiteBookingsController@edit')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.edit');
Route::post('/website-bookings/{id}/charge', 'WebsiteAdmin\WebsiteBookingsController@charge')->where('id', '[a-fA-F0-9]{24}')->name('wa.bookings.charge');
Route::post('/website-bookings/{id}/deposit/{action}', 'WebsiteAdmin\WebsiteBookingsController@deposit')->where(['id' => '[a-fA-F0-9]{24}', 'action' => 'place|capture|release'])->name('wa.bookings.deposit');
