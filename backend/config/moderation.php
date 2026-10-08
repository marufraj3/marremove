<?php

$adminEmails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('MODERATION_ADMIN_EMAILS', '')),
))));

$autoReviewThreshold = round(max(0.0, min(1.0, (float) env('MODERATION_AUTO_REVIEW_THRESHOLD', 0.70))), 3);
$autoHideThreshold = round(max($autoReviewThreshold, min(1.0, (float) env('MODERATION_AUTO_HIDE_THRESHOLD', 0.90))), 3);
$autoDeleteThreshold = round(max($autoHideThreshold, min(1.0, (float) env('MODERATION_AUTO_DELETE_THRESHOLD', 0.98))), 3);

return [
    // A comma-separated server-side allowlist. Empty means no account has moderation-admin access.
    'admin_emails' => $adminEmails,
    'regex_backtrack_limit' => max(1000, min(50000, (int) env('MODERATION_REGEX_BACKTRACK_LIMIT', 10000))),
    'regex_recursion_limit' => max(100, min(5000, (int) env('MODERATION_REGEX_RECURSION_LIMIT', 1000))),
    'max_comment_length' => max(1000, min(50000, (int) env('MODERATION_MAX_COMMENT_LENGTH', 20000))),
    'auto_delete_threshold' => $autoDeleteThreshold,
    'auto_hide_threshold' => $autoHideThreshold,
    'auto_review_threshold' => $autoReviewThreshold,
    'allow_ai_hide' => filter_var(env('MODERATION_ALLOW_AI_HIDE', true), FILTER_VALIDATE_BOOL),
    'allow_ai_delete' => filter_var(env('MODERATION_ALLOW_AI_DELETE', false), FILTER_VALIDATE_BOOL),
    'auto_hide_enabled' => filter_var(env('MODERATION_AUTO_HIDE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'auto_delete_enabled' => filter_var(env('MODERATION_AUTO_DELETE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'auto_execute_actions' => filter_var(env('MODERATION_AUTO_EXECUTE_ACTIONS', false), FILTER_VALIDATE_BOOL),
    'test_mode' => filter_var(env('MODERATION_TEST_MODE', false), FILTER_VALIDATE_BOOL),
    'action_queue_connection' => (string) env('MODERATION_ACTION_QUEUE_CONNECTION', 'database'),
    'action_max_attempts' => max(1, min(5, (int) env('MODERATION_ACTION_MAX_ATTEMPTS', 3))),
];
