<?php

return [
    // Enable only in the device build with its own SQLite database; never on the central web server.
    'enabled' => env('KARDEX_MOBILE_ENABLED', false),
    'desktop_runtime' => env('KARDEX_DESKTOP_RUNTIME', false),
    'desktop_origin' => env('KARDEX_DESKTOP_ORIGIN'),
    'server_url' => env('KARDEX_SERVER_URL'),
    'allow_local_http' => env('KARDEX_MOBILE_LOCAL_HTTP', false),
];
