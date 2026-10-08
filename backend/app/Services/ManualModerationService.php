<?php

namespace App\Services;

use App\Models\FacebookComment;
use App\Models\ModerationRule;
use Illuminate\Support\Facades\Log;

final class ManualModerationService
{
    /** @var array<string, list<ModerationRule>> */
    private array $rulesByPage = [];

    /** @var array<string, int> */
    private const ACTION_RANK = [
        'keep' => 1,
        'review' => 2,
        'hide' => 3,
        'delete' => 4,
    ];

    public function __construct(private readonly CommentTextNormalizer $normalizer)
    {
    }

    /** @return array<string, mixed> */
    public function evaluateComment(FacebookComment $comment): array
    {
        return $this->evaluateText(
            is_string($comment->message) ? $comment->message : '',
            is_string($comment->facebook_page_id) ? $comment->facebook_page_id : null,
        );
    }

    /**
     * Evaluate text without saving or otherwise changing a FacebookComment.
     * This method is used by the administrator rule tester.
     *
     * @return array<string, mixed>
     */
    public function evaluateText(string $text, ?string $facebookPageId = null): array
    {
        $text = $this->limitCommentLength($text);
        $commentVariants = $this->normalizer->matchingVariants($text);
        $canonicalText = $this->normalizer->canonicalText($text);
        $normalizedText = $this->normalizer->normalize($text);
        $rules = $this->activeRulesForPage($facebookPageId);
        $matches = [];

        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $commentVariants, $canonicalText, $normalizedText)) {
                $matches[] = $rule;
            }
        }

        if ($matches === []) {
            return [
                'matched' => false,
                'action' => 'none',
                'method' => 'manual',
                'category' => null,
                'severity' => null,
                'confidence' => 0.0,
                'reason' => null,
                'match_reason' => null,
                'rule_id' => null,
                'rule_name' => null,
                'rule_type' => null,
                'priority' => null,
            ];
        }

        usort($matches, static function (ModerationRule $left, ModerationRule $right): int {
            $priorityOrder = ((int) $right->priority) <=> ((int) $left->priority);
            if ($priorityOrder !== 0) {
                return $priorityOrder;
            }

            $actionOrder = (self::ACTION_RANK[$right->action] ?? 0) <=> (self::ACTION_RANK[$left->action] ?? 0);
            if ($actionOrder !== 0) {
                return $actionOrder;
            }

            return ((int) $left->getKey()) <=> ((int) $right->getKey());
        });

        $winner = $matches[0];

        return [
            'matched' => true,
            'action' => $winner->action,
            'method' => 'manual',
            'category' => $winner->category ?: $winner->rule_type,
            'severity' => $winner->severity,
            // This is a deterministic rule match indicator, not an AI confidence score.
            'confidence' => 1.0,
            'reason' => 'Matched manual rule: '.$winner->name,
            'match_reason' => $this->matchReason($winner->rule_type),
            'rule_id' => (int) $winner->getKey(),
            'rule_name' => $winner->name,
            'rule_type' => $winner->rule_type,
            'priority' => (int) $winner->priority,
        ];
    }

    /** @return array<string, mixed> */
    public function evaluateAndPersistComment(FacebookComment $comment): array
    {
        $result = $this->evaluateComment($comment);
        $comment->forceFill([
            'manual_moderation_status' => $result['matched'] ? 'matched' : 'no_manual_match',
            'manual_action' => $result['action'],
            'manual_rule_id' => $result['rule_id'],
            'manual_category' => $result['category'],
            'manual_severity' => $result['severity'],
            'manual_reason' => $result['reason'],
            'manual_checked_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * Return false for invalid, overlong, or deliberately unsafe regex patterns.
     * Regexes must use PHP/PCRE delimiter syntax, such as ~spam.{0,3}~iu.
     */
    public function isValidRegexPattern(string $pattern): bool
    {
        $firstCharacter = $pattern[0] ?? '';
        if (strlen($pattern) > 512
            || $pattern === ''
            || ctype_alnum($firstCharacter)
            || ctype_space($firstCharacter)
            || $firstCharacter === chr(92)) {
            return false;
        }

        // Recursion/subroutine calls and backreferences are not needed for moderation rules
        // and make administrator-supplied expressions harder to reason about safely.
        if (preg_match('/\(\?(?:R|0|[1-9][0-9]*|&|P>|P=|\()/i', $pattern) === 1
            || preg_match('~\\\\(?:[1-9]|k[<{]|g[<{])~i', $pattern) === 1) {
            return false;
        }

        return $this->withRegexLimits(static fn (): bool => @preg_match($pattern, '') !== false);
    }

    /** @return list<ModerationRule> */
    private function activeRulesForPage(?string $facebookPageId): array
    {
        $cacheKey = $facebookPageId ?? '*global*';
        if (array_key_exists($cacheKey, $this->rulesByPage)) {
            return $this->rulesByPage[$cacheKey];
        }

        $rules = ModerationRule::query()
            ->where('is_active', true)
            ->where(static function ($query) use ($facebookPageId): void {
                $query->whereNull('facebook_page_id');
                if ($facebookPageId !== null && $facebookPageId !== '') {
                    $query->orWhere('facebook_page_id', $facebookPageId);
                }
            })
            ->get()
            ->all();

        usort($rules, static function (ModerationRule $left, ModerationRule $right): int {
            $priorityOrder = ((int) $right->priority) <=> ((int) $left->priority);
            if ($priorityOrder !== 0) {
                return $priorityOrder;
            }

            $actionOrder = (self::ACTION_RANK[$right->action] ?? 0) <=> (self::ACTION_RANK[$left->action] ?? 0);
            if ($actionOrder !== 0) {
                return $actionOrder;
            }

            return ((int) $left->getKey()) <=> ((int) $right->getKey());
        });

        return $this->rulesByPage[$cacheKey] = $rules;
    }

    /** @param list<string> $commentVariants */
    private function ruleMatches(
        ModerationRule $rule,
        array $commentVariants,
        string $canonicalText,
        string $normalizedText,
    ): bool {
        return match ($rule->rule_type) {
            'keyword', 'phrase' => $this->matchesWordOrPhrase($rule->pattern, $commentVariants),
            'regex' => $this->matchesRegex($rule, $commentVariants),
            'url' => $this->containsUrl($canonicalText),
            'phone' => $this->containsPhoneNumber($canonicalText),
            'repeated_text' => $this->containsRepeatedText($rule->pattern, $normalizedText),
            default => false,
        };
    }

    /** @param list<string> $commentVariants */
    private function matchesWordOrPhrase(string $pattern, array $commentVariants): bool
    {
        $patternVariants = $this->normalizer->matchingVariants($pattern);

        foreach ($patternVariants as $patternVariant) {
            $words = preg_split('/\s+/u', $patternVariant, -1, PREG_SPLIT_NO_EMPTY);
            if (! is_array($words) || $words === []) {
                continue;
            }

            $expression = '/(?<![\p{L}\p{N}\p{M}_])'
                .implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words))
                .'(?![\p{L}\p{N}\p{M}_])/u';

            foreach ($commentVariants as $commentVariant) {
                if (@preg_match($expression, $commentVariant) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $commentVariants */
    private function matchesRegex(ModerationRule $rule, array $commentVariants): bool
    {
        foreach ($commentVariants as $variant) {
            $result = $this->withRegexLimits(
                static fn (): int|false => @preg_match($rule->pattern, $variant),
            );

            if ($result === 1) {
                return true;
            }

            if ($result === false) {
                Log::warning('Manual moderation regex could not be evaluated.', [
                    'rule_id' => $rule->getKey(),
                    'error_category' => $this->regexErrorCategory(preg_last_error()),
                    'pcre_error_code' => preg_last_error(),
                ]);

                return false;
            }
        }

        return false;
    }

    private function containsUrl(string $text): bool
    {
        $commonTlds = '(?:com|net|org|edu|gov|mil|int|biz|info|io|me|app|dev|co|uk|bd|in|us|ca|au|nz|jp|de|fr|xyz|online|site|shop|tech|live|tv|ai|gg|id|ph|news|store|pro|name|world|com\.bd|net\.bd|org\.bd)';
        $pattern = '~(?<![\p{L}\p{N}@])(?:https?://|www\.)[^\s<>()]+|'
            .'(?<![\p{L}\p{N}._%+@-])(?:[a-z0-9-]+\.)+'.$commonTlds.'(?![\p{L}\p{N}-]|\.[\p{L}\p{N}-])(?::\d{2,5})?(?:/[^\s<>()]*)?~iu';

        return $this->withRegexLimits(static fn (): int|false => @preg_match($pattern, $text)) === 1;
    }

    private function containsPhoneNumber(string $text): bool
    {
        $separator = '[\s().-]?';
        $bangladeshLocal = '01[3-9](?:'.$separator.'\d){8}';
        $bangladeshInternational = '\+?880'.$separator.'1[3-9](?:'.$separator.'\d){8}';
        $international = '\+\d(?:'.$separator.'\d){7,14}';
        $northAmerican = '(?:\([2-9]\d{2}\)|[2-9]\d{2})[ .-]?\d{3}[ .-]?\d{4}';
        $pattern = '~(?<![\p{L}\p{N}])(?:'.$bangladeshInternational.'|'.$bangladeshLocal.'|'.$international.'|'.$northAmerican.')(?!\d)~u';

        return $this->withRegexLimits(static fn (): int|false => @preg_match($pattern, $text)) === 1;
    }

    private function containsRepeatedText(string $pattern, string $normalized): bool
    {
        $threshold = is_numeric(trim($pattern)) ? (int) trim($pattern) : 3;
        $threshold = max(2, min(10, $threshold));
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($tokens) || count($tokens) < $threshold) {
            return false;
        }

        $maxPhraseLength = min(5, intdiv(count($tokens), $threshold));
        for ($phraseLength = 1; $phraseLength <= $maxPhraseLength; $phraseLength++) {
            $maxStart = count($tokens) - ($phraseLength * $threshold);
            for ($start = 0; $start <= $maxStart; $start++) {
                $phrase = array_slice($tokens, $start, $phraseLength);
                $repetitions = 1;
                $next = $start + $phraseLength;

                while ($next + $phraseLength <= count($tokens)
                    && array_slice($tokens, $next, $phraseLength) === $phrase) {
                    $repetitions++;
                    if ($repetitions >= $threshold) {
                        return true;
                    }
                    $next += $phraseLength;
                }
            }
        }

        return false;
    }

    private function matchReason(string $ruleType): string
    {
        return match ($ruleType) {
            'keyword' => 'Matched keyword',
            'phrase' => 'Matched phrase',
            'regex' => 'Matched regex',
            'url' => 'Matched URL',
            'phone' => 'Matched phone number',
            'repeated_text' => 'Matched repeated text',
            default => 'Matched manual rule',
        };
    }

    private function limitCommentLength(string $text): string
    {
        $limit = (int) config('moderation.max_comment_length', 20000);
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }

        return strlen($text) > ($limit * 4) ? substr($text, 0, $limit * 4) : $text;
    }

    private function regexErrorCategory(int $errorCode): string
    {
        return match ($errorCode) {
            PREG_BACKTRACK_LIMIT_ERROR => 'backtrack_limit',
            PREG_RECURSION_LIMIT_ERROR => 'recursion_limit',
            PREG_BAD_UTF8_ERROR, PREG_BAD_UTF8_OFFSET_ERROR => 'invalid_utf8',
            default => 'invalid_or_unsupported_pattern',
        };
    }

    private function withRegexLimits(callable $callback): mixed
    {
        $previousBacktrackLimit = ini_get('pcre.backtrack_limit');
        $previousRecursionLimit = ini_get('pcre.recursion_limit');
        @ini_set('pcre.backtrack_limit', (string) config('moderation.regex_backtrack_limit', 10000));
        @ini_set('pcre.recursion_limit', (string) config('moderation.regex_recursion_limit', 1000));

        try {
            return $callback();
        } finally {
            if ($previousBacktrackLimit !== false) {
                @ini_set('pcre.backtrack_limit', $previousBacktrackLimit);
            }
            if ($previousRecursionLimit !== false) {
                @ini_set('pcre.recursion_limit', $previousRecursionLimit);
            }
        }
    }
}
