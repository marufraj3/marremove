<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->page;
        $post = $this->post;
        $user = $request->user();
        $canOverride = $user instanceof User && $user->isModerationAdmin();

        return [
            'id' => $this->getKey(),
            'facebook_comment_id' => $this->facebook_comment_id,
            'facebook_page_id' => $this->facebook_page_id,
            'facebook_post_id' => $this->facebook_post_id,
            'parent_comment_id' => $this->parent_comment_id,
            'page' => $page === null ? null : [
                'id' => $page->getKey(),
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->page_name,
            ],
            'post' => $post === null ? null : [
                'facebook_post_id' => $post->facebook_post_id,
                'message' => $post->post_message,
                'created_at' => $post->post_created_at?->toISOString(),
            ],
            'author' => [
                'facebook_id' => $this->author_facebook_id,
                'name' => $this->author_name,
            ],
            'message' => $this->message,
            'created_at' => $this->comment_created_at?->toISOString(),
            'updated_at' => $this->comment_updated_at?->toISOString(),
            'manual_moderation' => [
                'status' => $this->manual_moderation_status,
                'action' => $this->manual_action,
                'rule_id' => $this->manual_rule_id,
                'category' => $this->manual_category,
                'severity' => $this->manual_severity,
                'reason' => $this->manual_reason,
                'checked_at' => $this->manual_checked_at?->toISOString(),
            ],
            'ai_moderation' => [
                'status' => $this->ai_status,
                'decision' => $this->ai_action,
                'category' => $this->ai_category,
                'confidence' => $this->ai_confidence,
                'severity' => $this->ai_severity,
                'reason' => $this->ai_reason,
                'checked_at' => $this->ai_checked_at?->toISOString(),
            ],
            'final_moderation' => [
                'status' => $this->final_status ?? 'pending',
                'action' => $this->final_action,
                'method' => $this->final_method,
                'source' => $this->final_source,
                'category' => $this->final_category,
                'confidence' => $this->final_confidence,
                'severity' => $this->final_severity,
                'reason' => $this->final_reason,
                'threshold_name' => $this->final_threshold_name,
                'threshold_value' => $this->final_threshold_value,
                'decision_at' => $this->final_decision_at?->toISOString(),
                'manual_override' => (bool) $this->manual_override,
                'overridden_at' => $this->overridden_at?->toISOString(),
                'overridden_by' => $canOverride ? $this->overridden_by : null,
            ],
            'facebook_action' => [
                'status' => $this->action_status,
                'requested_action' => $this->action_requested,
                'state' => $this->facebook_action_state,
                'attempt_count' => (int) $this->action_attempt_count,
                'attempted_at' => $this->action_attempted_at?->toISOString(),
                'completed_at' => $this->action_completed_at?->toISOString(),
                'failed_at' => $this->action_failed_at?->toISOString(),
                'error' => $this->action_error,
            ],
            'decision_explanation' => [
                'manual_match' => $this->manual_moderation_status === 'matched',
                'manual_status' => $this->manual_moderation_status,
                'manual_action' => $this->manual_action,
                'manual_rule_id' => $this->manual_rule_id,
                'ai_category' => $this->ai_category,
                'ai_confidence' => $this->ai_confidence,
                'threshold_name' => $this->final_threshold_name,
                'threshold_value' => $this->final_threshold_value,
                'final_decision' => $this->final_action,
                'final_reason' => $this->final_reason,
            ],
            'can_override' => $canOverride,
            'sync_status' => $page?->sync_status ?? 'unknown',
        ];
    }
}
