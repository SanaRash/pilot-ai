<?php

declare(strict_types=1);

namespace App\AI\Assistant;

use App\Entity\KnowledgeArticle;

final readonly class AssistantInput
{
    /**
     * @param list<KnowledgeArticle> $knowledgeArticles
     * @param list<AssistantHistoryMessage> $history
     */
    public function __construct(
        public string $clientMessage,
        public array $knowledgeArticles,
        public array $history = [],
        public ?string $ticketTitle = null,
        public ?string $ticketDescription = null,
        public ?string $categoryName = null,
    ) {
    }
}
