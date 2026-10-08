<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FacebookWebhookPayloadException;
use App\Exceptions\FacebookWebhookQueueException;
use App\Http\Controllers\Controller;
use App\Services\FacebookWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FacebookWebhookController extends Controller
{
    public function verify(Request $request, FacebookWebhookService $webhookService): Response
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $verifyToken = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));

        if (! $webhookService->verificationTokenConfigured()) {
            Log::error('Facebook webhook verification is not configured.', [
                'error_category' => 'verify_token_not_configured',
            ]);

            return response('Webhook verification is not configured.', 503);
        }

        if (! is_string($mode)
            || ! is_string($verifyToken)
            || ! is_string($challenge)
            || $challenge === ''
            || ! $webhookService->verificationMatches($mode, $verifyToken)) {
            Log::warning('Facebook webhook verification request was rejected.', [
                'error_category' => 'verification_mismatch',
            ]);

            return response('Webhook verification failed.', 403);
        }

        $webhookService->recordVerificationSuccess($verifyToken);

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request, FacebookWebhookService $webhookService): Response
    {
        $maxBytes = max(1, (int) config('services.facebook.webhook_max_payload_bytes', 1_048_576));
        $contentLength = filter_var($request->header('Content-Length'), FILTER_VALIDATE_INT);
        if (is_int($contentLength) && $contentLength > $maxBytes) {
            Log::warning('Facebook webhook payload was rejected.', [
                'error_category' => 'payload_too_large',
                'payload_bytes' => $contentLength,
                'max_payload_bytes' => $maxBytes,
            ]);

            return response('Webhook payload is too large.', 413);
        }

        $rawBody = $request->getContent();
        if (strlen($rawBody) > $maxBytes) {
            Log::warning('Facebook webhook payload was rejected.', [
                'error_category' => 'payload_too_large',
                'payload_bytes' => strlen($rawBody),
                'max_payload_bytes' => $maxBytes,
            ]);

            return response('Webhook payload is too large.', 413);
        }

        if (! $webhookService->verifySignature($rawBody, $request->header('X-Hub-Signature-256'))) {
            return response('Invalid webhook signature.', 401);
        }

        try {
            $webhookService->receive($rawBody);
        } catch (FacebookWebhookPayloadException) {
            return response('Invalid webhook payload.', 400);
        } catch (FacebookWebhookQueueException) {
            Log::error('Facebook webhook delivery could not be fully queued.', [
                'error_category' => 'queue_dispatch_failed',
            ]);

            return response('Webhook event could not be queued.', 503);
        } catch (Throwable $exception) {
            Log::error('Facebook webhook delivery could not be stored safely.', [
                'error_category' => 'webhook_handler_error',
                'exception_type' => $exception::class,
            ]);

            return response('Webhook is temporarily unavailable.', 503);
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function status(Request $request, FacebookWebhookService $webhookService): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['data' => $webhookService->statusFor($user)]);
    }
}
