<?php

$webhookJobTimeout = max(30, min(300, (int) env('FACEBOOK_WEBHOOK_JOB_TIMEOUT', 60)));
$webhookLeaseMinimum = $webhookJobTimeout + 10;
$webhookProcessingLease = max(
    $webhookLeaseMinimum,
    min(1800, (int) env('FACEBOOK_WEBHOOK_PROCESSING_LEASE_SECONDS', $webhookJobTimeout + 15)),
);

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    |
    | Integration settings are read only by the backend. Credentials belong
    | in this application's .env file and must never be exposed to the browser.
    |
    */

    'facebook' => [
        'graph_version' => env('FACEBOOK_GRAPH_VERSION'),
        'max_posts_per_sync' => (int) env('FACEBOOK_MAX_POSTS_PER_SYNC', 50),
        'max_comments_per_post' => (int) env('FACEBOOK_MAX_COMMENTS_PER_POST', 100),
        'max_pages_per_sync' => (int) env('FACEBOOK_MAX_PAGES_PER_SYNC', 100),
        'sync_queue_connection' => env('FACEBOOK_SYNC_QUEUE_CONNECTION', 'database'),
        'sync_max_attempts' => max(1, min(5, (int) env('FACEBOOK_SYNC_MAX_ATTEMPTS', 3))),
        'webhook_queue_connection' => env('FACEBOOK_WEBHOOK_QUEUE_CONNECTION', 'database'),
        'webhook_max_payload_bytes' => max(1024, min(5_242_880, (int) env('FACEBOOK_WEBHOOK_MAX_PAYLOAD_BYTES', 1_048_576))),
        'webhook_max_attempts' => max(1, min(10, (int) env('FACEBOOK_WEBHOOK_MAX_ATTEMPTS', 5))),
        'webhook_job_timeout' => $webhookJobTimeout,
        'webhook_processing_lease_seconds' => $webhookProcessingLease,
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

];
