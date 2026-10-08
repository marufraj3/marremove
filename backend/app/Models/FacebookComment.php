<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookComment extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'page_id',
        'post_id',
        'facebook_page_id',
        'facebook_post_id',
        'facebook_comment_id',
        'parent_comment_id',
        'author_facebook_id',
        'author_name',
        'message',
        'comment_created_at',
        'comment_updated_at',
        'manual_moderation_status',
        'manual_action',
        'manual_rule_id',
        'manual_category',
        'manual_severity',
        'manual_reason',
        'manual_checked_at',
        'ai_status',
        'ai_action',
        'ai_category',
        'ai_confidence',
        'ai_severity',
        'ai_reason',
        'ai_checked_at',
        'ai_input_hash',
        'ai_processing_started_at',
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
        'action_status',
        'action_requested',
        'action_attempt_count',
        'action_attempted_at',
        'action_completed_at',
        'action_failed_at',
        'action_error',
        'facebook_action_state',
        'facebook_action_at',
        'action_input_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'comment_created_at' => 'immutable_datetime',
            'comment_updated_at' => 'immutable_datetime',
            'manual_checked_at' => 'immutable_datetime',
            'ai_confidence' => 'float',
            'ai_checked_at' => 'immutable_datetime',
            'ai_processing_started_at' => 'immutable_datetime',
            'final_confidence' => 'float',
            'final_threshold_value' => 'float',
            'final_decision_at' => 'immutable_datetime',
            'manual_override' => 'boolean',
            'overridden_at' => 'immutable_datetime',
            'action_attempt_count' => 'integer',
            'action_attempted_at' => 'immutable_datetime',
            'action_completed_at' => 'immutable_datetime',
            'action_failed_at' => 'immutable_datetime',
            'facebook_action_at' => 'immutable_datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'page_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FacebookPost::class, 'post_id');
    }

    public function manualRule(): BelongsTo
    {
        return $this->belongsTo(ModerationRule::class, 'manual_rule_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ModerationActionLog::class, 'comment_id');
    }
}
