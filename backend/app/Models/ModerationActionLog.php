<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationActionLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'facebook_comment_id',
        'action',
        'status',
        'attempt_count',
        'response_code',
        'meta_error_code',
        'response_message',
        'processing_time_ms',
        'error_message',
        'actor_id',
        'is_manual',
        'is_test',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'response_code' => 'integer',
            'meta_error_code' => 'integer',
            'processing_time_ms' => 'integer',
            'is_manual' => 'boolean',
            'is_test' => 'boolean',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(FacebookComment::class, 'comment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
