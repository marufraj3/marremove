<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_moderation_settings', function (Blueprint $table) {
            $table->boolean('auto_hide_enabled')->default(true);
            $table->boolean('auto_delete_enabled')->default(false);
            $table->boolean('auto_execute_actions')->default(false);
        });

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->string('action_status', 24)->default('skipped')->index();
            $table->string('action_requested', 16)->nullable();
            $table->unsignedSmallInteger('action_attempt_count')->default(0);
            $table->timestamp('action_attempted_at')->nullable();
            $table->timestamp('action_completed_at')->nullable();
            $table->timestamp('action_failed_at')->nullable();
            $table->text('action_error')->nullable();
            $table->string('facebook_action_state', 16)->default('unknown')->index();
            $table->timestamp('facebook_action_at')->nullable();
            $table->char('action_input_hash', 64)->nullable()->index();
        });

        Schema::create('moderation_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('facebook_comments')->cascadeOnDelete();
            $table->string('facebook_comment_id', 255)->index();
            $table->string('action', 16)->index();
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('meta_error_code')->nullable();
            $table->string('response_message', 255)->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->string('error_message', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_test')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_action_logs');

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->dropIndex(['action_status']);
            $table->dropIndex(['facebook_action_state']);
            $table->dropIndex(['action_input_hash']);
            $table->dropColumn([
                'action_status',
                'action_requested',
                'action_attempt_count',
                'action_attempted_at',
                'action_completed_at',
                'action_failed_at',
                'action_error',
                'facebook_action_state',
                'facebook_action_at',
                'action_input_hash',
            ]);
        });

        Schema::table('page_moderation_settings', function (Blueprint $table) {
            $table->dropColumn([
                'auto_hide_enabled',
                'auto_delete_enabled',
                'auto_execute_actions',
            ]);
        });
    }
};
