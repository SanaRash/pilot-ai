<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\KnowledgeArticle;
use App\Entity\Ticket;
use App\Repository\AIAnalysisRepository;
use App\Repository\KnowledgeArticleRepository;

final readonly class KnowledgeSearchService
{
    private const MAX_ARTICLES = 3;

    public function __construct(
        private KnowledgeArticleRepository $knowledgeArticleRepository,
        private AIAnalysisRepository $aiAnalysisRepository,
    ) {
    }

    /**
     * @return list<KnowledgeArticle>
     */
    public function search(string $clientMessage, ?Ticket $ticket = null): array
    {
        $message = mb_strtolower($clientMessage, 'UTF-8');
        $messageTerms = $this->terms($message);
        $analysisKeywords = array_map(
            static fn (string $keyword): string => mb_strtolower($keyword, 'UTF-8'),
            array_values(array_filter(
                null === $ticket ? [] : ($this->aiAnalysisRepository->findLatestForTicket($ticket)?->getKeywords() ?? []),
                'is_string',
            )),
        );
        $ticketCategory = $ticket?->getCategory();
        $rankedArticles = [];

        foreach ($this->knowledgeArticleRepository->findClientSafeActive() as $position => $article) {
            $categoryMatch = null !== $ticketCategory
                && null !== $article->getCategory()
                && (
                    $ticketCategory === $article->getCategory()
                    || (null !== $ticketCategory->getId() && $ticketCategory->getId() === $article->getCategory()->getId())
                );
            $matchedArticleKeywords = $this->countKeywordMatches($article->getKeywords(), $message);
            $literalMatches = $this->countLiteralMatches(
                $messageTerms,
                mb_strtolower($article->getTitle().' '.$article->getContent(), 'UTF-8'),
            );
            $matchedAnalysisKeywords = $this->countAnalysisKeywordMatches($analysisKeywords, $article);

            if (!$categoryMatch && 0 === $matchedArticleKeywords && 0 === $literalMatches && 0 === $matchedAnalysisKeywords) {
                continue;
            }

            $rankedArticles[] = [
                'article' => $article,
                'rank' => [(int) $categoryMatch, $matchedArticleKeywords, $literalMatches, $matchedAnalysisKeywords],
                'position' => $position,
            ];
        }

        usort($rankedArticles, static function (array $left, array $right): int {
            foreach ($left['rank'] as $index => $leftScore) {
                $scoreComparison = $right['rank'][$index] <=> $leftScore;
                if (0 !== $scoreComparison) {
                    return $scoreComparison;
                }
            }

            return $left['position'] <=> $right['position'];
        });

        return array_values(array_map(
            static fn (array $ranked): KnowledgeArticle => $ranked['article'],
            array_slice($rankedArticles, 0, self::MAX_ARTICLES),
        ));
    }

    /**
     * @return list<string>
     */
    private function terms(string $value): array
    {
        $terms = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $terms) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $terms,
            static fn (string $term): bool => mb_strlen($term, 'UTF-8') >= 3,
        )));
    }

    /**
     * @param list<string> $keywords
     */
    private function countKeywordMatches(array $keywords, string $message): int
    {
        $matches = 0;
        foreach ($keywords as $keyword) {
            if (is_string($keyword) && '' !== trim($keyword) && str_contains($message, mb_strtolower($keyword, 'UTF-8'))) {
                ++$matches;
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $messageTerms
     */
    private function countLiteralMatches(array $messageTerms, string $articleText): int
    {
        $matches = 0;
        foreach ($messageTerms as $term) {
            if (str_contains($articleText, $term)) {
                ++$matches;
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $keywords
     */
    private function countAnalysisKeywordMatches(array $keywords, KnowledgeArticle $article): int
    {
        $articleText = mb_strtolower($article->getTitle().' '.$article->getContent(), 'UTF-8');
        $articleKeywords = array_map(
            static fn (string $keyword): string => mb_strtolower($keyword, 'UTF-8'),
            $article->getKeywords(),
        );
        $matches = 0;
        foreach ($keywords as $keyword) {
            if (
                str_contains($articleText, $keyword)
                || in_array($keyword, $articleKeywords, true)
            ) {
                ++$matches;
            }
        }

        return $matches;
    }
}
