<?php

// Record store directory + community pages moved from the nivessa.com admin
// (Sarah, 2026-10-15). Required inside web.php's authenticated group.

// Directory stores (+ per-store events)
Route::get('/website-directory/stores', 'WebsiteAdmin\DirectoryStoresController@index')->name('wa.directory.stores');
Route::get('/website-directory/stores/options', 'WebsiteAdmin\DirectoryStoresController@options')->name('wa.directory.storeOptions');
Route::post('/website-directory/stores', 'WebsiteAdmin\DirectoryStoresController@save')->name('wa.directory.storeSave');
Route::post('/website-directory/stores/{id}/delete', 'WebsiteAdmin\DirectoryStoresController@destroy')->name('wa.directory.storeDelete');
Route::post('/website-directory/stores/{id}/events', 'WebsiteAdmin\DirectoryStoresController@eventSave')->name('wa.directory.storeEventSave');
Route::post('/website-directory/stores/{id}/events/{eventId}/delete', 'WebsiteAdmin\DirectoryStoresController@eventDestroy')->name('wa.directory.storeEventDelete');

// Store submissions
Route::get('/website-directory/submissions', 'WebsiteAdmin\StoreSubmissionsController@index')->name('wa.directory.submissions');
Route::post('/website-directory/submissions/{id}/approve', 'WebsiteAdmin\StoreSubmissionsController@approve')->name('wa.directory.submissionApprove');
Route::post('/website-directory/submissions/{id}/reject', 'WebsiteAdmin\StoreSubmissionsController@reject')->name('wa.directory.submissionReject');
Route::post('/website-directory/submissions/{id}/delete', 'WebsiteAdmin\StoreSubmissionsController@destroy')->name('wa.directory.submissionDelete');

// Store claims
Route::get('/website-directory/claims', 'WebsiteAdmin\StoreClaimsController@index')->name('wa.directory.claims');
Route::post('/website-directory/claims/{id}/approve', 'WebsiteAdmin\StoreClaimsController@approve')->name('wa.directory.claimApprove');
Route::post('/website-directory/claims/{id}/reject', 'WebsiteAdmin\StoreClaimsController@reject')->name('wa.directory.claimReject');
Route::post('/website-directory/claims/{id}/delete', 'WebsiteAdmin\StoreClaimsController@destroy')->name('wa.directory.claimDelete');

// Store location requests (poll + write-ins)
Route::get('/website-directory/requests', 'WebsiteAdmin\StoreRequestsController@index')->name('wa.directory.requests');
Route::post('/website-directory/requests/poll/{slug}/remove-one', 'WebsiteAdmin\StoreRequestsController@removeCityVote')->name('wa.directory.requestCityVoteRemove');
Route::post('/website-directory/requests/{id}/handled', 'WebsiteAdmin\StoreRequestsController@handled')->name('wa.directory.requestHandled');
Route::post('/website-directory/requests/{id}/delete', 'WebsiteAdmin\StoreRequestsController@destroy')->name('wa.directory.requestDelete');

// Directory search analytics
Route::get('/website-directory/searches', 'WebsiteAdmin\DirectorySearchesController@index')->name('wa.directory.searches');

// Artist + Nivessa Index submissions
Route::get('/website-directory/artist-submissions', 'WebsiteAdmin\ArtistSubmissionsController@index')->name('wa.directory.artists');
Route::post('/website-directory/artist-submissions/{id}/approve', 'WebsiteAdmin\ArtistSubmissionsController@approve')->name('wa.directory.artistApprove');
Route::post('/website-directory/artist-submissions/{id}/reject', 'WebsiteAdmin\ArtistSubmissionsController@reject')->name('wa.directory.artistReject');
Route::get('/website-directory/index-submissions', 'WebsiteAdmin\IndexSubmissionsController@index')->name('wa.directory.indexSubmissions');
Route::post('/website-directory/index-submissions/{id}/approve', 'WebsiteAdmin\IndexSubmissionsController@approve')->name('wa.directory.indexApprove');
Route::post('/website-directory/index-submissions/{id}/reject', 'WebsiteAdmin\IndexSubmissionsController@reject')->name('wa.directory.indexReject');

// Community-submitted events
Route::get('/website-events/community-events', 'WebsiteAdmin\CommunityEventsController@index')->name('wa.directory.communityEvents');
Route::post('/website-events/community-events/{id}/approve', 'WebsiteAdmin\CommunityEventsController@approve')->name('wa.directory.communityEventApprove');
Route::post('/website-events/community-events/{id}/reject', 'WebsiteAdmin\CommunityEventsController@reject')->name('wa.directory.communityEventReject');
Route::post('/website-events/community-events/{id}/delete', 'WebsiteAdmin\CommunityEventsController@destroy')->name('wa.directory.communityEventDelete');

// Listening party prep board (ERP events store)
Route::get('/website-events/listening-party-prep', 'WebsiteAdmin\ListeningPartyPrepController@index')->name('wa.directory.partyPrep');
Route::post('/website-events/listening-party-prep/{id}', 'WebsiteAdmin\ListeningPartyPrepController@save')->name('wa.directory.partyPrepSave');

// Website event/RSVP/preorder actions the ERP Events pages were missing
Route::post('/website-events/upload-image', 'WebsiteAdmin\EventsExtrasController@uploadImage')->name('wa.directory.eventImageUpload');
Route::post('/website-events/rsvps/{rsvpId}/delete', 'WebsiteAdmin\EventsExtrasController@rsvpDelete')->name('wa.directory.rsvpDelete');
Route::post('/website-events/rsvps/{rsvpId}/add-guest', 'WebsiteAdmin\EventsExtrasController@rsvpAddGuest')->name('wa.directory.rsvpAddGuest');
Route::post('/website-events/preorders/{preorderId}/paid', 'WebsiteAdmin\EventsExtrasController@preorderPaid')->name('wa.directory.preorderPaid');
Route::post('/website-events/preorders/{preorderId}/notes', 'WebsiteAdmin\EventsExtrasController@preorderNotes')->name('wa.directory.preorderNotes');
Route::post('/website-events/{id}/published', 'WebsiteAdmin\EventsExtrasController@published')->name('wa.directory.eventPublished');
Route::get('/website-events/{id}/rsvps.csv', 'WebsiteAdmin\EventsExtrasController@rsvpsCsv')->name('wa.directory.eventRsvpsCsv');
