<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_rules', function (Blueprint $table) {
            $table->id();
            // This stores Meta's Graph Page ID, matching facebook_pages.facebook_page_id.
            // No FK is used so disconnecting a Page cannot turn a Page rule into a global rule.
            $table->string('facebook_page_id', 64)->nullable()->index();
            $table->string('name', 120);
            $table->string('category', 64)->nullable();
            $table->string('rule_type', 32)->index();
            $table->string('pattern', 512);
            $table->string('action', 24)->index();
            $table->string('severity', 24)->default('medium');
            $table->integer('priority')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->string('manual_moderation_status', 32)->nullable()->index();
            $table->string('manual_action', 24)->default('none')->index();
            $table->foreignId('manual_rule_id')
                ->nullable()
                ->constrained('moderation_rules')
                ->nullOnDelete();
            $table->string('manual_category', 64)->nullable();
            $table->string('manual_severity', 24)->nullable();
            $table->text('manual_reason')->nullable();
            $table->timestamp('manual_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facebook_comments', function (Blueprint $table) {
            $table->dropForeign(['manual_rule_id']);
            $table->dropColumn([
                'manual_moderation_status',
                'manual_action',
                'manual_rule_id',
                'manual_category',
                'manual_severity',
                'manual_reason',
                'manual_checked_at',
            ]);
        });

        Schema::dropIfExists('moderation_rules');
    }
};
