<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModerationLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'provider',
        'model',
        'status',
        'decision',
        'category',
        'confidence',
        'severity',
        'reason',
        'request_metadata',
        'response_metadata',
        'error_message',
        'processing_time_ms',
        'attempt_count',
        'input_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'request_metadata' => 'array',
            'response_metadata' => 'array',
            'processing_time_ms' => 'integer',
            'attempt_count' => 'integer',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(FacebookComment::class, 'comment_id');
    }
}
