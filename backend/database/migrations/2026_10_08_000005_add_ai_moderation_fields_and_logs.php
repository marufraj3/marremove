<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true);
        });

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->string('ai_status', 24)->nullable()->index();
            $table->string('ai_action', 24)->nullable();
            $table->string('ai_category', 32)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->string('ai_severity', 24)->nullable();
            $table->text('ai_reason')->nullable();
            $table->timestamp('ai_checked_at')->nullable();
            $table->char('ai_input_hash', 64)->nullable()->index();
            $table->timestamp('ai_processing_started_at')->nullable();
        });

        Schema::create('ai_moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->nullable()->constrained('facebook_comments')->nullOnDelete();
            $table->string('provider', 32)->default('gemini');
            $table->string('model', 120);
            $table->string('status', 24);
            $table->string('decision', 24)->nullable();
            $table->string('category', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('severity', 24)->nullable();
            $table->string('reason', 320)->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->string('error_message', 96)->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->char('input_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'input_hash']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_moderation_logs');

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->dropIndex(['ai_status']);
            $table->dropIndex(['ai_input_hash']);
            $table->dropColumn([
                'ai_status',
                'ai_action',
                'ai_category',
                'ai_confidence',
                'ai_severity',
                'ai_reason',
                'ai_checked_at',
                'ai_input_hash',
                'ai_processing_started_at',
            ]);
        });

        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
    }
};
