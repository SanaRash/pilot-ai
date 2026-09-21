<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\AIAnalysisRepository;
use App\Repository\TicketRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SimilarTicketFinder
{
    private const int MAX_TERMS = 20;
    private const int MIN_TERM_LENGTH = 4;
    private const int MAX_CANDIDATES = 50;
    private const int MAX_RESULTS = 5;

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly AIAnalysisRepository $aiAnalysisRepository,
    ) {
    }

    /**
     * @return list<Ticket>
     */
    public function findSimilar(Ticket $ticket, User $viewer): array
    {
        if (!in_array('ROLE_TECHNICIAN', $viewer->getRoles(), true)) {
            throw new AccessDeniedException('La recherche de tickets similaires est réservée aux techniciens.');
        }

        $latestAnalysis = $this->aiAnalysisRepository->findLatestForTicket($ticket);
        $terms = $this->extractTerms([
            ...($latestAnalysis?->getKeywords() ?? []),
            $ticket->getTitle(),
            $ticket->getDescription(),
        ]);
        $candidates = $this->ticketRepository->findSimilarityCandidates(
            $ticket,
            $terms,
            $this->normalizeText($ticket->getTitle()),
            self::MAX_CANDIDATES,
        );

        $scoredTickets = [];

        foreach ($candidates as $candidate) {
            $score = $this->calculateScore($ticket, $candidate, $terms);

            if ($score > 0) {
                $scoredTickets[] = ['ticket' => $candidate, 'score' => $score];
            }
        }

        usort($scoredTickets, static function (array $left, array $right): int {
            $scoreComparison = $right['score'] <=> $left['score'];

            if (0 !== $scoreComparison) {
                return $scoreComparison;
            }

            $dateComparison = ($right['ticket']->getCreatedAt()?->getTimestamp() ?? 0)
                <=> ($left['ticket']->getCreatedAt()?->getTimestamp() ?? 0);

            if (0 !== $dateComparison) {
                return $dateComparison;
            }

            return ($right['ticket']->getId() ?? 0) <=> ($left['ticket']->getId() ?? 0);
        });

        return array_map(
            static fn (array $scoredTicket): Ticket => $scoredTicket['ticket'],
            array_slice($scoredTickets, 0, self::MAX_RESULTS),
        );
    }

    /**
     * @param list<string> $terms
     */
    public function calculateScore(Ticket $ticket, Ticket $candidate, array $terms): int
    {
        $score = 0;
        $ticketTitle = $this->normalizeText($ticket->getTitle());
        $candidateTitle = $this->normalizeText($candidate->getTitle());
        $candidateDescription = $this->normalizeText($candidate->getDescription());

        if ('' !== $ticketTitle && $ticketTitle === $candidateTitle) {
            $score += 8;
        }

        $ticketCategoryId = $ticket->getCategory()?->getId();

        if (null !== $ticketCategoryId && $ticketCategoryId === $candidate->getCategory()?->getId()) {
            $score += 3;
        }

        $scoredTerms = [];

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);

            if (mb_strlen($normalizedTerm) < self::MIN_TERM_LENGTH || isset($scoredTerms[$normalizedTerm])) {
                continue;
            }

            $scoredTerms[$normalizedTerm] = true;

            if (str_contains($candidateTitle, $normalizedTerm)) {
                $score += 2;
            }

            if (str_contains($candidateDescription, $normalizedTerm)) {
                ++$score;
            }

            if (self::MAX_TERMS === count($scoredTerms)) {
                break;
            }
        }

        return $score;
    }

    /**
     * @param array<array-key, mixed> $sources
     *
     * @return list<string>
     */
    private function extractTerms(array $sources): array
    {
        $terms = [];

        foreach ($sources as $source) {
            if (!is_string($source)) {
                continue;
            }

            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($source), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                if (mb_strlen($term) < self::MIN_TERM_LENGTH || isset($terms[$term])) {
                    continue;
                }

                $terms[$term] = true;

                if (self::MAX_TERMS === count($terms)) {
                    break 2;
                }
            }
        }

        return array_keys($terms);
    }

    private function normalizeText(?string $value): string
    {
        return trim(mb_strtolower($value ?? ''), ' ');
    }
}
