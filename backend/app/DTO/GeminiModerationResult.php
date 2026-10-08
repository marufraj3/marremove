<?php

namespace App\DTO;

final readonly class GeminiModerationResult
{
    /**
     * @param array{decision: string, category: string, confidence: float, severity: string, reason: string} $classification
     * @param array<string, scalar|null> $requestMetadata
     * @param array<string, scalar|null> $responseMetadata
     */
    public function __construct(
        public array $classification,
        public string $status,
        public ?string $errorCategory,
        public string $model,
        public int $processingTimeMs,
        public int $attemptCount,
        public array $requestMetadata = [],
        public array $responseMetadata = [],
    ) {
    }
}
