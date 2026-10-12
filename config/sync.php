<?php

return [

    // Data packages synced for every day.
    'packages' => ['purchase', 'sales'],

    // Quinos lists come newest-created first. A day sync stops reading a list
    // once it reaches documents created more than this many days before the
    // earliest requested date (documents can be back-dated, but rarely by more).
    // "Re-sync" ignores this limit and reads the whole list.
    'lookback_days' => (int) env('SYNC_LOOKBACK_DAYS', 45),

    // Items, departments, contacts and sales types are refreshed at most this often.
    'master_ttl_hours' => (int) env('SYNC_MASTER_TTL_HOURS', 6),

    // Run a sync right after the web response instead of waiting for the queue worker.
    // Needs php-fpm or LiteSpeed; elsewhere (and in the console) syncs are queued as before.
    'run_after_response' => (bool) env('SYNC_AFTER_RESPONSE', true),

    // Most days one request may queue.
    'max_days_per_request' => 62,

];
