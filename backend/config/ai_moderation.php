<?php

return [
    // Feature flag is backend-only and disabled until explicitly enabled in .env.
    'enabled' => filter_var(env('GEMINI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'model' => trim((string) env('GEMINI_MODEL', 'gemini-3.8-flash')),
    'timeout' => max(2, min(120, (int) env('GEMINI_TIMEOUT', 20))),
    'max_retries' => max(0, min(5, (int) env('GEMINI_MAX_RETRIES', 3))),
    'queue_connection' => trim((string) env('GEMINI_QUEUE_CONNECTION', 'database')),
    'retry_base_delay_ms' => max(0, min(5000, (int) env('GEMINI_RETRY_BASE_DELAY_MS', 250))),
    'max_comment_characters' => max(1000, min(50000, (int) env('GEMINI_MAX_COMMENT_CHARACTERS', 20000))),
    'max_post_context_characters' => max(0, min(10000, (int) env('GEMINI_MAX_POST_CONTEXT_CHARACTERS', 5000))),
    'min_action_confidence' => 0.65,
    'failure_decision' => 'review',
    'prompt_version' => 'v1',
];
