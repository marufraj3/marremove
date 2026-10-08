<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('facebook_pages')->cascadeOnDelete();
            $table->string('facebook_page_id', 64)->index();
            $table->string('facebook_post_id', 191)->unique();
            $table->longText('post_message')->nullable();
            $table->string('post_type')->nullable();
            $table->timestamp('post_created_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['page_id', 'post_created_at']);
        });

        Schema::create('facebook_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('facebook_pages')->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('facebook_posts')->nullOnDelete();
            $table->string('facebook_page_id', 64)->index();
            $table->string('facebook_post_id', 191)->nullable()->index();
            $table->string('facebook_comment_id', 191)->unique();
            $table->string('parent_comment_id', 191)->nullable()->index();
            $table->string('author_facebook_id', 191)->nullable();
            $table->string('author_name')->nullable();
            $table->longText('message')->nullable();
            $table->timestamp('comment_created_at')->nullable();
            $table->timestamp('comment_updated_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'comment_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_comments');
        Schema::dropIfExists('facebook_posts');
    }
};
