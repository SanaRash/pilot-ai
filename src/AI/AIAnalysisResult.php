<?php

declare(strict_types=1);

namespace App\AI;

final readonly class AIAnalysisResult
{
    /**
     * @param list<string>|null $keywords
     * @param list<string>|null $suggestions
     */
    public function __construct(
        public ?string $summary,
        public ?string $suggestedPriority,
        public ?string $suggestedCategory,
        public ?array $keywords,
        public ?array $suggestions,
    ) {
    }
}
