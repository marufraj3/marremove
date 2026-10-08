<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookPost extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'page_id',
        'facebook_page_id',
        'facebook_post_id',
        'post_message',
        'post_type',
        'post_created_at',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'post_created_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'page_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FacebookComment::class, 'post_id');
    }
}
