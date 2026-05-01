<?php

return [

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'null')),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'host' => env('PUSHER_HOST', 'soketi'),
                'port' => (int) env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => env('PUSHER_SCHEME', 'http') === 'https',
                'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
