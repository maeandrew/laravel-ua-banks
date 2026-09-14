<?php

use Maeandrew\UaBanks\Enums\BankStatus;

return [

    // Storage driver: "snapshot" (JSON file, works out of the box), "database", or a custom one
    // registered with UaBanks::extend('name', fn ($app) => new MyRepository).
    'driver' => env('UA_BANKS_DRIVER', 'snapshot'),

    'source' => [
        // NBU open data endpoint; `typ` and `json` query parameters are appended automatically.
        'base_url' => env('UA_BANKS_SOURCE_URL', 'https://bank.gov.ua/NBU_BankInfo/get_data_branch'),

        // HTTP timeout per request, in seconds.
        'timeout' => 20,

        // Additional attempts after a failed request (connection error, 403, 429, 5xx).
        'retries' => 3,

        // Delay before each retry, in seconds; the last value is reused if there are more retries.
        'backoff' => [1, 3, 9],

        // User-Agent header sent to the NBU.
        'user_agent' => 'maeandrew/laravel-ua-banks',
    ],

    'sync' => [
        // Minimum number of head offices a response must contain to be accepted (bypass with --force).
        'min_banks' => 50,
    ],

    'snapshot' => [
        // Where `ua-banks:sync` writes the JSON file for the snapshot driver. When the file does not
        // exist, the snapshot bundled with the package is used.
        'path' => storage_path('app/ua-banks/banks.json'),
    ],

    'database' => [
        // Database connection for the database driver; null uses the default connection.
        'connection' => env('UA_BANKS_DB_CONNECTION'),

        // Table names used by the database driver and the published migration.
        'tables' => [
            'banks' => 'ua_banks',
            'aliases' => 'ua_bank_mfo_aliases',
        ],
    ],

    'cache' => [
        // Cache store for parsed snapshot data; null uses the default store.
        'store' => env('UA_BANKS_CACHE_STORE'),

        // Cache lifetime in seconds; 0 disables the Laravel cache (the in-process memo stays).
        'ttl' => 86400,
    ],

    // UaBanks::isStale() returns true when the data is older than this many days.
    'stale_after_days' => 14,

    'schedule' => [
        // Register `ua-banks:sync` in the Laravel scheduler automatically.
        'enabled' => (bool) env('UA_BANKS_SCHEDULE', false),

        // Cron expression for the scheduled sync (default: daily at 04:17).
        'cron' => '17 4 * * *',

        // Timezone the cron expression is evaluated in.
        'timezone' => 'Europe/Kyiv',
    ],

    'validation' => [
        // What the rules do when there is no registry data at all: "pass" (only format and checksum
        // are checked) or "fail".
        'on_missing_registry' => 'pass',

        // Bank statuses accepted by UaIban (and UaMfo::operating()) by default.
        'allowed_statuses' => [BankStatus::Normal],
    ],

];
