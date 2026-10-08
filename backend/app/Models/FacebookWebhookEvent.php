<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookWebhookEvent extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_key',
        'page_id',
        'facebook_page_id',
        'facebook_comment_id',
        'facebook_post_id',
        'event_type',
        'status',
        'error_category',
        'attempts',
        'received_at',
        'dispatched_at',
        'processing_started_at',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'page_id');
    }
}
