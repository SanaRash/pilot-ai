<?php

declare(strict_types=1);

namespace App\AI;

interface AIProviderInterface
{
    public function analyze(AIAnalysisInput $input): AIAnalysisResult;
}
