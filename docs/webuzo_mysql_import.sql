-- Marremove Laravel schema for a fresh MySQL/MariaDB database.
-- Import this file only after selecting an EMPTY database in phpMyAdmin.
-- It contains the final schema from backend/database/migrations and records
-- those migrations in Laravel's migrations table so artisan does not rerun them.
-- No DROP statements and no application/user data are included.
-- Recommended: MySQL 8.0+ with InnoDB and utf8mb4.

SET NAMES utf8mb4;

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` VARCHAR(255) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` TEXT NULL,
  `payload` LONGTEXT NOT NULL,
  `last_activity` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` VARCHAR(255) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  `expiration` BIGINT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` VARCHAR(255) NOT NULL,
  `owner` VARCHAR(255) NOT NULL,
  `expiration` BIGINT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL,
  `reserved_at` INT UNSIGNED NULL DEFAULT NULL,
  `available_at` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `total_jobs` INT NOT NULL,
  `pending_jobs` INT NOT NULL,
  `failed_jobs` INT NOT NULL,
  `failed_job_ids` LONGTEXT NOT NULL,
  `options` MEDIUMTEXT NULL,
  `cancelled_at` INT NULL DEFAULT NULL,
  `created_at` INT NOT NULL,
  `finished_at` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(255) NOT NULL,
  `connection` VARCHAR(255) NOT NULL,
  `queue` VARCHAR(255) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`, `queue`, `failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facebook_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `facebook_page_id` VARCHAR(64) NOT NULL,
  `page_name` VARCHAR(255) NOT NULL,
  `page_username` VARCHAR(255) NULL DEFAULT NULL,
  `page_category` VARCHAR(255) NULL DEFAULT NULL,
  `page_picture_url` TEXT NULL,
  `page_access_token` LONGTEXT NOT NULL,
  `token_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `sync_status` VARCHAR(24) NOT NULL DEFAULT 'idle',
  `sync_error` TEXT NULL,
  `last_sync_started_at` TIMESTAMP NULL DEFAULT NULL,
  `posts_synced_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `comments_synced_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `webhook_last_received_at` TIMESTAMP NULL DEFAULT NULL,
  `webhook_last_processed_at` TIMESTAMP NULL DEFAULT NULL,
  `ai_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facebook_pages_facebook_page_id_unique` (`facebook_page_id`),
  KEY `facebook_pages_sync_status_index` (`sync_status`),
  CONSTRAINT `fk_facebook_pages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facebook_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` BIGINT UNSIGNED NOT NULL,
  `facebook_page_id` VARCHAR(64) NOT NULL,
  `facebook_post_id` VARCHAR(191) NOT NULL,
  `post_message` LONGTEXT NULL,
  `post_type` VARCHAR(255) NULL DEFAULT NULL,
  `post_created_at` TIMESTAMP NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facebook_posts_facebook_post_id_unique` (`facebook_post_id`),
  KEY `facebook_posts_facebook_page_id_index` (`facebook_page_id`),
  KEY `facebook_posts_page_id_post_created_at_index` (`page_id`, `post_created_at`),
  CONSTRAINT `fk_facebook_posts_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `moderation_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `facebook_page_id` VARCHAR(64) NULL DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(64) NULL DEFAULT NULL,
  `rule_type` VARCHAR(32) NOT NULL,
  `pattern` VARCHAR(512) NOT NULL,
  `action` VARCHAR(24) NOT NULL,
  `severity` VARCHAR(24) NOT NULL DEFAULT 'medium',
  `priority` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `moderation_rules_facebook_page_id_index` (`facebook_page_id`),
  KEY `moderation_rules_rule_type_index` (`rule_type`),
  KEY `moderation_rules_action_index` (`action`),
  KEY `moderation_rules_priority_index` (`priority`),
  KEY `moderation_rules_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facebook_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `facebook_page_id` VARCHAR(64) NOT NULL,
  `facebook_post_id` VARCHAR(191) NULL DEFAULT NULL,
  `facebook_comment_id` VARCHAR(191) NOT NULL,
  `parent_comment_id` VARCHAR(191) NULL DEFAULT NULL,
  `author_facebook_id` VARCHAR(191) NULL DEFAULT NULL,
  `author_name` VARCHAR(255) NULL DEFAULT NULL,
  `message` LONGTEXT NULL,
  `comment_created_at` TIMESTAMP NULL DEFAULT NULL,
  `comment_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `manual_moderation_status` VARCHAR(32) NULL DEFAULT NULL,
  `manual_action` VARCHAR(24) NOT NULL DEFAULT 'none',
  `manual_rule_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `manual_category` VARCHAR(64) NULL DEFAULT NULL,
  `manual_severity` VARCHAR(24) NULL DEFAULT NULL,
  `manual_reason` TEXT NULL,
  `manual_checked_at` TIMESTAMP NULL DEFAULT NULL,
  `ai_status` VARCHAR(24) NULL DEFAULT NULL,
  `ai_action` VARCHAR(24) NULL DEFAULT NULL,
  `ai_category` VARCHAR(32) NULL DEFAULT NULL,
  `ai_confidence` DECIMAL(4,3) NULL DEFAULT NULL,
  `ai_severity` VARCHAR(24) NULL DEFAULT NULL,
  `ai_reason` TEXT NULL,
  `ai_checked_at` TIMESTAMP NULL DEFAULT NULL,
  `ai_input_hash` CHAR(64) NULL DEFAULT NULL,
  `ai_processing_started_at` TIMESTAMP NULL DEFAULT NULL,
  `final_status` VARCHAR(24) NULL DEFAULT NULL,
  `final_action` VARCHAR(24) NULL DEFAULT NULL,
  `final_method` VARCHAR(24) NULL DEFAULT NULL,
  `final_source` VARCHAR(32) NULL DEFAULT NULL,
  `final_category` VARCHAR(64) NULL DEFAULT NULL,
  `final_confidence` DECIMAL(4,3) NULL DEFAULT NULL,
  `final_severity` VARCHAR(24) NULL DEFAULT NULL,
  `final_reason` TEXT NULL,
  `final_threshold_name` VARCHAR(32) NULL DEFAULT NULL,
  `final_threshold_value` DECIMAL(4,3) NULL DEFAULT NULL,
  `final_decision_at` TIMESTAMP NULL DEFAULT NULL,
  `final_input_hash` CHAR(64) NULL DEFAULT NULL,
  `manual_override` TINYINT(1) NOT NULL DEFAULT 0,
  `overridden_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `overridden_at` TIMESTAMP NULL DEFAULT NULL,
  `manual_override_hash` CHAR(64) NULL DEFAULT NULL,
  `action_status` VARCHAR(24) NOT NULL DEFAULT 'skipped',
  `action_requested` VARCHAR(16) NULL DEFAULT NULL,
  `action_attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `action_attempted_at` TIMESTAMP NULL DEFAULT NULL,
  `action_completed_at` TIMESTAMP NULL DEFAULT NULL,
  `action_failed_at` TIMESTAMP NULL DEFAULT NULL,
  `action_error` TEXT NULL,
  `facebook_action_state` VARCHAR(16) NOT NULL DEFAULT 'unknown',
  `facebook_action_at` TIMESTAMP NULL DEFAULT NULL,
  `action_input_hash` CHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facebook_comments_facebook_comment_id_unique` (`facebook_comment_id`),
  KEY `facebook_comments_facebook_page_id_index` (`facebook_page_id`),
  KEY `facebook_comments_facebook_post_id_index` (`facebook_post_id`),
  KEY `facebook_comments_parent_comment_id_index` (`parent_comment_id`),
  KEY `facebook_comments_page_id_comment_created_at_index` (`page_id`, `comment_created_at`),
  KEY `facebook_comments_manual_moderation_status_index` (`manual_moderation_status`),
  KEY `facebook_comments_manual_action_index` (`manual_action`),
  KEY `facebook_comments_ai_status_index` (`ai_status`),
  KEY `facebook_comments_ai_input_hash_index` (`ai_input_hash`),
  KEY `facebook_comments_final_status_index` (`final_status`),
  KEY `facebook_comments_final_action_index` (`final_action`),
  KEY `facebook_comments_final_input_hash_index` (`final_input_hash`),
  KEY `facebook_comments_manual_override_index` (`manual_override`),
  KEY `facebook_comments_action_status_index` (`action_status`),
  KEY `facebook_comments_facebook_action_state_index` (`facebook_action_state`),
  KEY `facebook_comments_action_input_hash_index` (`action_input_hash`),
  CONSTRAINT `fk_facebook_comments_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_facebook_comments_post` FOREIGN KEY (`post_id`) REFERENCES `facebook_posts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facebook_comments_manual_rule` FOREIGN KEY (`manual_rule_id`) REFERENCES `moderation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facebook_comments_overridden_by` FOREIGN KEY (`overridden_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facebook_webhook_statuses` (
  `status_key` VARCHAR(32) NOT NULL,
  `verify_token_fingerprint` CHAR(64) NULL DEFAULT NULL,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`status_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facebook_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_key` CHAR(64) NOT NULL,
  `page_id` BIGINT UNSIGNED NOT NULL,
  `facebook_page_id` VARCHAR(64) NOT NULL,
  `facebook_comment_id` VARCHAR(191) NOT NULL,
  `facebook_post_id` VARCHAR(191) NULL DEFAULT NULL,
  `event_type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'queued',
  `error_category` VARCHAR(64) NULL DEFAULT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `received_at` TIMESTAMP NOT NULL,
  `dispatched_at` TIMESTAMP NULL DEFAULT NULL,
  `processing_started_at` TIMESTAMP NULL DEFAULT NULL,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `facebook_webhook_events_event_key_unique` (`event_key`),
  KEY `facebook_webhook_events_facebook_page_id_index` (`facebook_page_id`),
  KEY `facebook_webhook_events_facebook_comment_id_index` (`facebook_comment_id`),
  KEY `facebook_webhook_events_facebook_post_id_index` (`facebook_post_id`),
  KEY `facebook_webhook_events_status_index` (`status`),
  KEY `facebook_webhook_events_page_id_received_at_index` (`page_id`, `received_at`),
  CONSTRAINT `fk_facebook_webhook_events_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_moderation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT 'gemini',
  `model` VARCHAR(120) NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `decision` VARCHAR(24) NULL DEFAULT NULL,
  `category` VARCHAR(32) NULL DEFAULT NULL,
  `confidence` DECIMAL(4,3) NULL DEFAULT NULL,
  `severity` VARCHAR(24) NULL DEFAULT NULL,
  `reason` VARCHAR(320) NULL DEFAULT NULL,
  `request_metadata` JSON NULL,
  `response_metadata` JSON NULL,
  `error_message` VARCHAR(96) NULL DEFAULT NULL,
  `processing_time_ms` INT UNSIGNED NULL DEFAULT NULL,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `input_hash` CHAR(64) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_moderation_logs_comment_id_input_hash_index` (`comment_id`, `input_hash`),
  KEY `ai_moderation_logs_provider_created_at_index` (`provider`, `created_at`),
  CONSTRAINT `fk_ai_moderation_logs_comment` FOREIGN KEY (`comment_id`) REFERENCES `facebook_comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `page_moderation_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` BIGINT UNSIGNED NOT NULL,
  `facebook_page_id` VARCHAR(64) NOT NULL,
  `ai_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `manual_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_delete_threshold` DECIMAL(4,3) NOT NULL DEFAULT 0.980,
  `auto_hide_threshold` DECIMAL(4,3) NOT NULL DEFAULT 0.900,
  `auto_review_threshold` DECIMAL(4,3) NOT NULL DEFAULT 0.700,
  `allow_ai_delete` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_ai_hide` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_hide_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_delete_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_execute_actions` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_moderation_settings_page_id_unique` (`page_id`),
  UNIQUE KEY `page_moderation_settings_facebook_page_id_unique` (`facebook_page_id`),
  CONSTRAINT `fk_page_moderation_settings_page` FOREIGN KEY (`page_id`) REFERENCES `facebook_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `moderation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `manual_result` JSON NULL,
  `ai_result` JSON NULL,
  `final_result` JSON NOT NULL,
  `processing_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `source` VARCHAR(32) NOT NULL,
  `input_hash` CHAR(64) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `moderation_logs_comment_id_created_at_index` (`comment_id`, `created_at`),
  KEY `moderation_logs_source_index` (`source`),
  CONSTRAINT `fk_moderation_logs_comment` FOREIGN KEY (`comment_id`) REFERENCES `facebook_comments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_moderation_logs_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `moderation_action_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `facebook_comment_id` VARCHAR(255) NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `response_code` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `meta_error_code` INT UNSIGNED NULL DEFAULT NULL,
  `response_message` VARCHAR(255) NULL DEFAULT NULL,
  `processing_time_ms` INT UNSIGNED NULL DEFAULT NULL,
  `error_message` VARCHAR(255) NULL DEFAULT NULL,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `is_test` TINYINT(1) NOT NULL DEFAULT 0,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `moderation_action_logs_facebook_comment_id_index` (`facebook_comment_id`),
  KEY `moderation_action_logs_action_index` (`action`),
  KEY `moderation_action_logs_status_index` (`status`),
  KEY `moderation_action_logs_comment_id_created_at_index` (`comment_id`, `created_at`),
  KEY `moderation_action_logs_status_created_at_index` (`status`, `created_at`),
  CONSTRAINT `fk_moderation_action_logs_comment` FOREIGN KEY (`comment_id`) REFERENCES `facebook_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moderation_action_logs_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Laravel migration repository. Importing these rows marks all migrations above as applied.
CREATE TABLE `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2026_10_08_000000_create_facebook_pages_table', 1),
('2026_10_08_000001_create_facebook_posts_and_comments_tables', 1),
('2026_10_08_000002_add_sync_tracking_to_facebook_pages_table', 1),
('2026_10_08_000003_create_facebook_webhook_tables', 1),
('2026_10_08_000004_create_moderation_rules_and_add_manual_comment_fields', 1),
('2026_10_08_000005_add_ai_moderation_fields_and_logs', 1),
('2026_10_08_000006_create_final_moderation_pipeline', 1),
('2026_10_08_000007_add_moderation_action_pipeline', 1);
