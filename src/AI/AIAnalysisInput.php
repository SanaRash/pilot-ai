<?php

declare(strict_types=1);

namespace App\AI;

final readonly class AIAnalysisInput
{
    /**
     * @param list<string> $allowedCategories
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $allowedCategories = [],
    ) {
    }
}
