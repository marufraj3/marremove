<?php

namespace App\Services;

use App\Models\FacebookPage;
use App\Models\PageModerationSetting;
use Illuminate\Support\Facades\DB;

final class PageModerationSettingsService
{
    public function forPage(FacebookPage $page): PageModerationSetting
    {
        return DB::transaction(function () use ($page): PageModerationSetting {
            $settings = PageModerationSetting::query()->firstOrCreate(
                ['page_id' => $page->getKey()],
                $this->defaultsFor($page),
            );

            if ($settings->facebook_page_id !== $page->facebook_page_id) {
                $settings->forceFill(['facebook_page_id' => $page->facebook_page_id])->save();
            }

            return $settings;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(FacebookPage $page, array $attributes): PageModerationSetting
    {
        return DB::transaction(function () use ($page, $attributes): PageModerationSetting {
            $settings = $this->forPage($page);
            $settings->fill($attributes)->save();

            if (array_key_exists('ai_enabled', $attributes)) {
                $page->forceFill(['ai_enabled' => (bool) $attributes['ai_enabled']])->save();
            }

            return $settings->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function toArray(PageModerationSetting $settings): array
    {
        return [
            'ai_enabled' => (bool) $settings->ai_enabled,
            'manual_enabled' => (bool) $settings->manual_enabled,
            'auto_delete_threshold' => (float) $settings->auto_delete_threshold,
            'auto_hide_threshold' => (float) $settings->auto_hide_threshold,
            'auto_review_threshold' => (float) $settings->auto_review_threshold,
            'allow_ai_delete' => (bool) $settings->allow_ai_delete,
            'allow_ai_hide' => (bool) $settings->allow_ai_hide,
            'auto_hide_enabled' => (bool) $settings->auto_hide_enabled,
            'auto_delete_enabled' => (bool) $settings->auto_delete_enabled,
            'auto_execute_actions' => (bool) $settings->auto_execute_actions,
        ];
    }

    /** @return array<string, mixed> */
    private function defaultsFor(FacebookPage $page): array
    {
        return [
            'facebook_page_id' => $page->facebook_page_id,
            'ai_enabled' => (bool) $page->ai_enabled,
            'manual_enabled' => true,
            'auto_delete_threshold' => (float) config('moderation.auto_delete_threshold', 0.98),
            'auto_hide_threshold' => (float) config('moderation.auto_hide_threshold', 0.90),
            'auto_review_threshold' => (float) config('moderation.auto_review_threshold', 0.70),
            'allow_ai_delete' => (bool) config('moderation.allow_ai_delete', false),
            'allow_ai_hide' => (bool) config('moderation.allow_ai_hide', true),
            'auto_hide_enabled' => (bool) config('moderation.auto_hide_enabled', true),
            'auto_delete_enabled' => (bool) config('moderation.auto_delete_enabled', false),
            'auto_execute_actions' => (bool) config('moderation.auto_execute_actions', false),
        ];
    }
}
