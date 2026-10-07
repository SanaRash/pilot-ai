<?php

declare(strict_types=1);

namespace App\AI\Assistant;

final class AssistantException extends \RuntimeException
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
