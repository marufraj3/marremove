<?php

namespace App\Services;

use JsonException;

final class GeminiModerationResponseValidator
{
    public const DECISIONS = ['keep', 'hide', 'delete', 'review'];

    public const CATEGORIES = [
        'clean',
        'profanity',
        'insult',
        'harassment',
        'sexual',
        'hate',
        'threat',
        'spam',
        'scam',
        'competitor_spam',
        'negative_feedback',
        'customer_complaint',
        'irrelevant',
        'suspicious',
        'other',
    ];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * @return array{valid: bool, result: array{decision: string, category: string, confidence: float, severity: string, reason: string}, error_category: ?string}
     */
    public function validate(string $rawResponse): array
    {
        try {
            $decoded = json_decode($rawResponse, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid('invalid_json');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->invalid('invalid_shape');
        }

        $expectedKeys = ['decision', 'category', 'confidence', 'severity', 'reason'];
        $actualKeys = array_keys($decoded);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            return $this->invalid('invalid_shape');
        }

        $decision = $decoded['decision'];
        $category = $decoded['category'];
        $confidence = $decoded['confidence'];
        $severity = $decoded['severity'];
        $reason = $decoded['reason'];

        if (! is_string($decision) || ! in_array($decision, self::DECISIONS, true)) {
            return $this->invalid('invalid_decision');
        }
        if (! is_string($category) || ! in_array($category, self::CATEGORIES, true)) {
            return $this->invalid('invalid_category');
        }
        if (! is_string($severity) || ! in_array($severity, self::SEVERITIES, true)) {
            return $this->invalid('invalid_severity');
        }
        if ((! is_int($confidence) && ! is_float($confidence))
            || ! is_finite((float) $confidence)
            || $confidence < 0
            || $confidence > 1) {
            return $this->invalid('invalid_confidence');
        }
        if (! is_string($reason)) {
            return $this->invalid('invalid_reason');
        }

        $reason = preg_replace('/[\p{Z}\s]+/u', ' ', trim($reason));
        if (! is_string($reason) || $reason === '' || $this->characterLength($reason) > 320) {
            return $this->invalid('invalid_reason');
        }

        return [
            'valid' => true,
            'result' => [
                'decision' => $decision,
                'category' => $category,
                'confidence' => (float) $confidence,
                'severity' => $severity,
                'reason' => $reason,
            ],
            'error_category' => null,
        ];
    }

    /** @param array{decision: string, category: string, confidence: float, severity: string, reason: string} $result
     *  @return array{decision: string, category: string, confidence: float, severity: string, reason: string}
     */
    public function applySafeDecisionPolicy(array $result): array
    {
        $decision = $result['decision'];
        $safeFeedbackCategories = ['clean', 'negative_feedback', 'customer_complaint', 'irrelevant', 'suspicious', 'other'];
        $minimumConfidence = (float) config('ai_moderation.min_action_confidence', 0.65);

        // The final decision engine enforces per-Page hide/delete thresholds and permissions.
        // This validator only prevents aggressive actions on protected/low-confidence categories.
        if ((in_array($result['category'], $safeFeedbackCategories, true) && in_array($decision, ['hide', 'delete'], true))
            || ($result['confidence'] < $minimumConfidence && $decision !== 'review')) {
            $result['decision'] = 'review';
        }

        return $result;
    }

    /** @return array{decision: string, category: string, confidence: float, severity: string, reason: string} */
    public function invalidResponseFallback(): array
    {
        return [
            'decision' => 'review',
            'category' => 'other',
            'confidence' => 0.0,
            'severity' => 'low',
            'reason' => 'Invalid AI response',
        ];
    }

    /** @return array{valid: false, result: array{decision: string, category: string, confidence: float, severity: string, reason: string}, error_category: string} */
    private function invalid(string $errorCategory): array
    {
        return [
            'valid' => false,
            'result' => $this->invalidResponseFallback(),
            'error_category' => $errorCategory,
        ];
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}
