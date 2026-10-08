<?php

namespace App\Http\Requests;

use App\Models\FacebookPage;
use App\Models\ModerationRule;
use App\Services\CommentTextNormalizer;
use App\Services\ManualModerationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ModerationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The moderation.admin route middleware performs the administrator check.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$requiredOrSometimes, 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rule_type' => [$requiredOrSometimes, Rule::in(['keyword', 'phrase', 'regex', 'url', 'phone', 'repeated_text'])],
            'pattern' => [$requiredOrSometimes, 'string', 'max:512'],
            'action' => [$requiredOrSometimes, Rule::in(['keep', 'review', 'hide', 'delete'])],
            'severity' => [$requiredOrSometimes, Rule::in(['low', 'medium', 'high', 'critical'])],
            'priority' => [$requiredOrSometimes, 'integer', 'between:-100000,100000'],
            'facebook_page_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $routeRule = $this->route('rule');
            $existingRule = $routeRule instanceof ModerationRule ? $routeRule : null;
            $ruleType = $this->input('rule_type', $existingRule?->rule_type);
            $pattern = $this->input('pattern', $existingRule?->pattern);

            if (in_array($ruleType, ['keyword', 'phrase'], true)
                && is_string($pattern)
                && app(CommentTextNormalizer::class)->normalize($pattern) === '') {
                $validator->errors()->add('pattern', 'A non-empty word or phrase is required for this rule type.');
            }

            if ($ruleType === 'repeated_text' && is_string($pattern)) {
                $threshold = trim($pattern);
                if (! ctype_digit($threshold) || (int) $threshold < 2 || (int) $threshold > 10) {
                    $validator->errors()->add('pattern', 'Repeated-text count must be a whole number from 2 to 10.');
                }
            }

            if ($ruleType === 'regex' && is_string($pattern)) {
                if (! app(ManualModerationService::class)->isValidRegexPattern($pattern)) {
                    $validator->errors()->add(
                        'pattern',
                        'Enter a valid, safe PCRE pattern with delimiters, such as ~spam.{0,3}~iu.',
                    );
                }
            }

            $facebookPageId = $this->input('facebook_page_id');
            if ($facebookPageId !== null
                && is_string($facebookPageId)
                && $facebookPageId !== ''
                && ! FacebookPage::query()
                    ->where('facebook_page_id', $facebookPageId)
                    ->where('user_id', $this->user()?->getAuthIdentifier())
                    ->where('is_active', true)
                    ->exists()) {
                $validator->errors()->add('facebook_page_id', 'Choose an active connected Facebook Page or select All Pages.');
            }
        });
    }
}
