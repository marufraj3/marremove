<?php

namespace App\Services;

final class ModerationSafetyMode
{
    /**
     * Live Facebook mutations are permitted only in production and only when
     * the administrator has explicitly left the configured test switch off.
     */
    public function enabled(): bool
    {
        return (bool) config('moderation.test_mode', false)
            || ! app()->environment('production');
    }
}
