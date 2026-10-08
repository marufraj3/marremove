<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationRule extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'facebook_page_id',
        'name',
        'category',
        'rule_type',
        'pattern',
        'action',
        'severity',
        'priority',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id', 'facebook_page_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FacebookComment::class, 'manual_rule_id');
    }
}
