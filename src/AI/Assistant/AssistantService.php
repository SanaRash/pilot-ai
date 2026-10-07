<?php

declare(strict_types=1);

namespace App\AI\Assistant;

use App\Entity\KnowledgeArticle;
use App\Entity\Ticket;

final readonly class AssistantService
{
    public function __construct(private AssistantProviderInterface $provider)
    {
    }

    /**
     * @param list<KnowledgeArticle> $knowledgeArticles
     * @param list<AssistantHistoryMessage> $history
     */
    public function reply(string $clientMessage, array $knowledgeArticles, ?Ticket $ticket = null, array $history = []): AssistantReply
    {
        $safeArticles = array_values(array_slice(array_filter(
            $knowledgeArticles,
            static fn (KnowledgeArticle $article): bool => $article->isActive() && $article->isClientSafe(),
        ), 0, 3));

        $reply = $this->provider->generateReply(new AssistantInput(
            clientMessage: $clientMessage,
            knowledgeArticles: $safeArticles,
            history: $history,
            ticketTitle: $ticket?->getTitle(),
            ticketDescription: $ticket?->getDescription(),
            categoryName: $ticket?->getCategory()?->getName(),
        ));

        $answer = trim($reply->answer);
        if ('' === $answer || mb_strlen($answer, 'UTF-8') > 2_000) {
            throw new AssistantException('Le provider de l’assistant a retourné une réponse invalide.');
        }

        return new AssistantReply($answer, [] === $safeArticles ? true : $reply->needsTechnician);
    }
}
