<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cooldown Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the default cooldown storage driver that will be
    | used when managing cooldowns across your application. You may specify
    | any of the drivers defined in the "drivers" array below.
    |
    | Supported: "cache", "database"
    |
    */

    'default' => env('COOLDOWN_DRIVER', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Cooldown Storage Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the cooldown storage drivers used by your
    | application. The "cache" driver leverages Laravel's cache stores for
    | ultra-fast, in-memory TTL lookups. The "database" driver provides
    | persistence across server reboots and auditable historical tracking.
    |
    */

    'drivers' => [

        'cache' => [
            'driver' => 'cache',
            'store'  => env('COOLDOWN_CACHE_STORE', null),
            'prefix' => 'cooldown:',
        ],

        'database' => [
            'driver'     => 'database',
            'table'      => 'cooldowns',
            'connection' => env('COOLDOWN_DB_CONNECTION', null),
            /*
            |--------------------------------------------------------------------------
            | Pruning Configuration
            |--------------------------------------------------------------------------
            |
            | Expired cooldown database records will automatically be pruned when
            | running the "model:prune" artisan command or via scheduled tasks.
            |
            */
            'prune_after_days' => 7,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cooldown Events
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will dispatch native Laravel events whenever a
    | cooldown is initiated, checked against expiration, or reset. You may
    | disable this option to maximize performance in high-throughput loops.
    |
    */

    'events' => [
        'dispatch' => true,
    ],

];
