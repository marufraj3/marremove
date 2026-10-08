<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasToken = is_string($this->page_access_token) && trim($this->page_access_token) !== '';
        $isExpired = $this->token_expires_at !== null && $this->token_expires_at->isPast();

        return [
            'id' => $this->getKey(),
            'facebook_page_id' => $this->facebook_page_id,
            'page_name' => $this->page_name,
            'page_username' => $this->page_username,
            'page_category' => $this->page_category,
            'page_picture_url' => $this->page_picture_url,
            'is_active' => (bool) $this->is_active,
            'token_status' => ! $this->is_active
                ? 'disconnected'
                : (! $hasToken || $isExpired ? 'expired_or_invalid' : 'connected'),
            'comments_count' => (int) ($this->comments_count ?? 0),
            'webhook_status' => $this->webhook_last_received_at ? 'received' : 'waiting',
            'webhook_last_received_at' => $this->webhook_last_received_at?->toISOString(),
            'ai_enabled' => (bool) $this->ai_enabled,
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'last_sync_started_at' => $this->last_sync_started_at?->toISOString(),
            'sync_status' => $this->sync_status ?? 'idle',
            'sync_error' => $this->sync_error,
            'posts_synced_count' => (int) ($this->posts_synced_count ?? 0),
            'comments_synced_count' => (int) ($this->comments_synced_count ?? 0),
        ];
    }
}
