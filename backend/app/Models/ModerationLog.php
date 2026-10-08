<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'comment_id',
        'actor_id',
        'manual_result',
        'ai_result',
        'final_result',
        'processing_time_ms',
        'source',
        'input_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'manual_result' => 'array',
            'ai_result' => 'array',
            'final_result' => 'array',
            'processing_time_ms' => 'integer',
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
