<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModerationRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->page;

        return [
            'id' => (int) $this->getKey(),
            'facebook_page_id' => $this->facebook_page_id,
            'page' => $page === null ? null : [
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->page_name,
            ],
            'name' => $this->name,
            'category' => $this->category,
            'rule_type' => $this->rule_type,
            'pattern' => $this->pattern,
            'action' => $this->action,
            'severity' => $this->severity,
            'priority' => (int) $this->priority,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
