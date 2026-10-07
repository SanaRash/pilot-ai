<?php

declare(strict_types=1);

namespace App\AI\Assistant;

final readonly class AssistantHistoryMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public string $role,
        public string $content,
    ) {
    }
}
