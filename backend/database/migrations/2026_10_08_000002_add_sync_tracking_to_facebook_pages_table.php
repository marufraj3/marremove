<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->string('sync_status', 24)->default('idle')->index();
            $table->text('sync_error')->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->unsignedInteger('posts_synced_count')->default(0);
            $table->unsignedInteger('comments_synced_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropColumn([
                'sync_status',
                'sync_error',
                'last_sync_started_at',
                'posts_synced_count',
                'comments_synced_count',
            ]);
        });
    }
};
