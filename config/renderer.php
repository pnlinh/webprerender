<?php

return [
    'bots' => [
        'googlebot',
        'developers.google.com/+/web/snippet',
    ],
    'host_url' => env('RENDERER_HOST_URL', 'https://fptshop.com.vn'),
    'time_rerender_file' => env('TIME_RERENDER_FILE', 60),
    'debug_mode' => env('RENDERER_DEBUG_MODE', false),
    'reponsive_mode' => env('REPONSIVE_MODE', true),
];
