<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exception\AIValidationException;
use App\Entity\AIAnalysis;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AIService
{
    private const MAX_SUMMARY_LENGTH = 2_000;
    private const MAX_CATEGORY_LENGTH = 100;
    private const MAX_KEYWORDS = 20;
    private const MAX_KEYWORD_LENGTH = 100;
    private const MAX_SUGGESTIONS = 10;
    private const MAX_SUGGESTION_LENGTH = 1_000;

    public function __construct(
        private AIProviderInterface $provider,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function analyzeTicket(Ticket $ticket): AIAnalysisResult
    {
        $title = $this->validateRequiredText($ticket->getTitle(), 'titre');
        $description = $this->validateRequiredText($ticket->getDescription(), 'description');

        $result = $this->provider->analyze(new AIAnalysisInput(
            title: $title,
            description: $description,
        ));

        $this->validateResult($result);

        $analysis = (new AIAnalysis())
            ->setSummary($result->summary)
            ->setSuggestedPriority($result->suggestedPriority)
            ->setSuggestedCategory($result->suggestedCategory)
            ->setKeywords($result->keywords)
            ->setSuggestions($result->suggestions)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setTicket($ticket);

        $this->entityManager->persist($analysis);
        $this->entityManager->flush();

        return $result;
    }

    private function validateRequiredText(?string $value, string $field): string
    {
        if (null === $value || 1 !== preg_match('/\S/u', $value)) {
            throw new AIValidationException(sprintf('Le %s du ticket est requis pour l’analyse IA.', $field));
        }

        return $value;
    }

    private function validateResult(AIAnalysisResult $result): void
    {
        $this->validateRequiredResultText($result->summary, self::MAX_SUMMARY_LENGTH, 'résumé');
        $this->validateRequiredResultText($result->suggestedCategory, self::MAX_CATEGORY_LENGTH, 'catégorie suggérée');

        if (null === $result->suggestedPriority || !in_array($result->suggestedPriority, Ticket::ALLOWED_PRIORITIES, true)) {
            throw new AIValidationException('La priorité suggérée par le provider IA est invalide.');
        }

        $this->validateRequiredTextList(
            $result->keywords,
            self::MAX_KEYWORDS,
            self::MAX_KEYWORD_LENGTH,
            'mots-clés',
        );
        $this->validateRequiredTextList(
            $result->suggestions,
            self::MAX_SUGGESTIONS,
            self::MAX_SUGGESTION_LENGTH,
            'suggestions',
        );
    }

    private function validateRequiredResultText(?string $value, int $maxLength, string $field): void
    {
        if (
            null === $value
            || 1 !== preg_match('/\S/u', $value)
            || mb_strlen($value, 'UTF-8') > $maxLength
        ) {
            throw new AIValidationException(sprintf('Le champ %s retourné par le provider IA est invalide.', $field));
        }
    }

    /**
     * @param array<array-key, mixed>|null $values
     */
    private function validateRequiredTextList(?array $values, int $maxItems, int $maxItemLength, string $field): void
    {
        if (null === $values || [] === $values || !array_is_list($values) || count($values) > $maxItems) {
            throw new AIValidationException(sprintf('Le champ %s retourné par le provider IA est invalide.', $field));
        }

        $seen = [];

        foreach ($values as $value) {
            if (
                !is_string($value)
                || 1 !== preg_match('/\S/u', $value)
                || mb_strlen($value, 'UTF-8') > $maxItemLength
                || in_array($value, $seen, true)
            ) {
                throw new AIValidationException(sprintf('Le champ %s retourné par le provider IA est invalide.', $field));
            }

            $seen[] = $value;
        }
    }
}
