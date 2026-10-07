<?php

declare(strict_types=1);

namespace App\AI\Assistant;

interface AssistantProviderInterface
{
    public function generateReply(AssistantInput $input): AssistantReply;
}
