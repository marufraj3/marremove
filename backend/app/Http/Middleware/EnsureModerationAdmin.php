<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModerationAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        if (! method_exists($user, 'isModerationAdmin') || ! $user->isModerationAdmin()) {
            return response()->json(['message' => 'Administrator access is required.'], 403);
        }

        return $next($request);
    }
}
