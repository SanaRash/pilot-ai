<?php

declare(strict_types=1);

namespace App\AI\Assistant;

final readonly class AssistantReply
{
    public function __construct(
        public string $answer,
        public bool $needsTechnician,
    ) {
    }
}
