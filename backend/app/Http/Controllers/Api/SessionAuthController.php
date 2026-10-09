<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SessionAuthController extends Controller
{
    /**
     * Start the Laravel web session and let the web middleware issue the XSRF cookie.
     */
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $credentials = [
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
        ];

        $guard = Auth::guard('web');
        if (! $guard->attempt($credentials, false)) {
            return $this->invalidCredentials();
        }

        $request->session()->regenerate();
        $user = $guard->user();

        if (! $user instanceof User || ! $user->isModerationAdmin()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->invalidCredentials();
        }

        return response()->json([
            'data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'message' => 'Signed in successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json([
            'message' => 'Email or password is incorrect, or this account is not authorized.',
        ], 422);
    }
}
