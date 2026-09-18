<?php

declare(strict_types=1);

namespace App\AI;

final readonly class AIAnalysisInput
{
    public function __construct(
        public string $title,
        public string $description,
    ) {
    }
}
