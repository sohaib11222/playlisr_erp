<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discogs release import UI
    |--------------------------------------------------------------------------
    |
    | When false, the dedicated import page and sidebar entry are hidden and
    | the import routes return 404. Does not affect price suggestions or listings.
    |
    */
    'import_enabled' => env('DISCOGS_IMPORT_ENABLED', true),

];
