<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->timestamp('webhook_last_received_at')->nullable();
            $table->timestamp('webhook_last_processed_at')->nullable();
        });

        Schema::create('facebook_webhook_statuses', function (Blueprint $table) {
            $table->string('status_key', 32)->primary();
            $table->char('verify_token_fingerprint', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('facebook_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->foreignId('page_id')->constrained('facebook_pages')->cascadeOnDelete();
            $table->string('facebook_page_id', 64)->index();
            $table->string('facebook_comment_id', 191)->index();
            $table->string('facebook_post_id', 191)->nullable()->index();
            $table->string('event_type', 32);
            $table->string('status', 24)->default('queued')->index();
            $table->string('error_category', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_webhook_events');
        Schema::dropIfExists('facebook_webhook_statuses');

        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_last_received_at',
                'webhook_last_processed_at',
            ]);
        });
    }
};
