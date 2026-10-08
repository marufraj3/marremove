<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageModerationSetting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'page_id',
        'facebook_page_id',
        'ai_enabled',
        'manual_enabled',
        'auto_delete_threshold',
        'auto_hide_threshold',
        'auto_review_threshold',
        'allow_ai_delete',
        'allow_ai_hide',
        'auto_hide_enabled',
        'auto_delete_enabled',
        'auto_execute_actions',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'manual_enabled' => 'boolean',
            'auto_delete_threshold' => 'float',
            'auto_hide_threshold' => 'float',
            'auto_review_threshold' => 'float',
            'allow_ai_delete' => 'boolean',
            'allow_ai_hide' => 'boolean',
            'auto_hide_enabled' => 'boolean',
            'auto_delete_enabled' => 'boolean',
            'auto_execute_actions' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'page_id');
    }
}
