<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FacebookPage extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'facebook_page_id',
        'page_name',
        'page_username',
        'page_category',
        'page_picture_url',
        'page_access_token',
        'token_expires_at',
        'is_active',
        'ai_enabled',
        'last_synced_at',
        'sync_status',
        'sync_error',
        'last_sync_started_at',
        'posts_synced_count',
        'comments_synced_count',
        'webhook_last_received_at',
        'webhook_last_processed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'page_access_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'ai_enabled' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
            'last_sync_started_at' => 'immutable_datetime',
            'posts_synced_count' => 'integer',
            'comments_synced_count' => 'integer',
            'webhook_last_received_at' => 'immutable_datetime',
            'webhook_last_processed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FacebookPost::class, 'page_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FacebookComment::class, 'page_id');
    }

    public function moderationSettings(): HasOne
    {
        return $this->hasOne(PageModerationSetting::class, 'page_id');
    }
}
