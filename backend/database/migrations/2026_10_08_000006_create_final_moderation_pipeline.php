<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_moderation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->unique()->constrained('facebook_pages')->cascadeOnDelete();
            $table->string('facebook_page_id', 64)->unique();
            $table->boolean('ai_enabled')->default(true);
            $table->boolean('manual_enabled')->default(true);
            $table->decimal('auto_delete_threshold', 4, 3)->default(0.980);
            $table->decimal('auto_hide_threshold', 4, 3)->default(0.900);
            $table->decimal('auto_review_threshold', 4, 3)->default(0.700);
            $table->boolean('allow_ai_delete')->default(false);
            $table->boolean('allow_ai_hide')->default(true);
            $table->timestamps();
        });

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->string('final_status', 24)->nullable()->index();
            $table->string('final_action', 24)->nullable()->index();
            $table->string('final_method', 24)->nullable();
            $table->string('final_source', 32)->nullable();
            $table->string('final_category', 64)->nullable();
            $table->decimal('final_confidence', 4, 3)->nullable();
            $table->string('final_severity', 24)->nullable();
            $table->text('final_reason')->nullable();
            $table->string('final_threshold_name', 32)->nullable();
            $table->decimal('final_threshold_value', 4, 3)->nullable();
            $table->timestamp('final_decision_at')->nullable();
            $table->char('final_input_hash', 64)->nullable()->index();
            $table->boolean('manual_override')->default(false)->index();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->char('manual_override_hash', 64)->nullable();
        });

        Schema::create('moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->nullable()->constrained('facebook_comments')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('manual_result')->nullable();
            $table->json('ai_result')->nullable();
            $table->json('final_result');
            $table->unsignedInteger('processing_time_ms')->default(0);
            $table->string('source', 32);
            $table->char('input_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'created_at']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->dropForeign(['overridden_by']);
            $table->dropIndex(['final_status']);
            $table->dropIndex(['final_action']);
            $table->dropIndex(['final_input_hash']);
            $table->dropIndex(['manual_override']);
            $table->dropColumn([
                'final_status',
                'final_action',
                'final_method',
                'final_source',
                'final_category',
                'final_confidence',
                'final_severity',
                'final_reason',
                'final_threshold_name',
                'final_threshold_value',
                'final_decision_at',
                'final_input_hash',
                'manual_override',
                'overridden_by',
                'overridden_at',
                'manual_override_hash',
            ]);
        });

        Schema::dropIfExists('page_moderation_settings');
    }
};
