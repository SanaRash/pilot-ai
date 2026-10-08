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
    private const int MAX_KEYWORDS = 20;
    private const int MIN_TERM_LENGTH = 4;
    private const int MAX_CANDIDATES = 50;
    private const int MAX_RESULTS = 3;
    private const int MIN_SCORE = 7;
    private const array STOP_WORDS = [
        'autre' => true,
        'avoir' => true,
        'avec' => true,
        'bien' => true,
        'bonjour' => true,
        'dans' => true,
        'deja' => true,
        'demande' => true,
        'depuis' => true,
        'etre' => true,
        'faire' => true,
        'mais' => true,
        'merci' => true,
        'plus' => true,
        'pour' => true,
        'probleme' => true,
        'reste' => true,
        'sans' => true,
        'ticket' => true,
        'tres' => true,
        'verifie' => true,
        'verifier' => true,
    ];

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
        $signals = $this->extractSignals(
            $latestAnalysis?->getKeywords() ?? [],
            $ticket->getTitle(),
            $ticket->getDescription(),
        );
        $prefilterTerms = $this->mergeSignalsForPrefilter($signals['keywords'], $signals['terms']);
        $candidates = $this->ticketRepository->findSimilarityCandidates(
            $ticket,
            $prefilterTerms,
            $this->normalizeText($ticket->getTitle()),
            self::MAX_CANDIDATES,
        );

        $scoredTickets = [];

        foreach ($candidates as $candidate) {
            $score = $this->calculateSignalScore($ticket, $candidate, $signals['keywords'], $signals['terms']);

            if ($score >= self::MIN_SCORE) {
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
            $score += 10;
        }

        $ticketCategoryId = $ticket->getCategory()?->getId();

        if (null !== $ticketCategoryId && $ticketCategoryId === $candidate->getCategory()?->getId()) {
            $score += 6;
        }

        $scoredTerms = [];

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeText($term);

            if (mb_strlen($normalizedTerm) < self::MIN_TERM_LENGTH || isset($scoredTerms[$normalizedTerm])) {
                continue;
            }

            $scoredTerms[$normalizedTerm] = true;

            if (str_contains($candidateTitle, $normalizedTerm)) {
                $score += 3;
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
     * @param list<string> $keywords
     * @param list<string> $terms
     */
    private function calculateSignalScore(Ticket $ticket, Ticket $candidate, array $keywords, array $terms): int
    {
        $score = $this->calculateScore($ticket, $candidate, $terms);
        $candidateTitle = $this->normalizeText($candidate->getTitle());
        $candidateDescription = $this->normalizeText($candidate->getDescription());
        $scoredKeywords = [];

        foreach ($keywords as $keyword) {
            $normalizedKeyword = $this->normalizeText($keyword);

            if (mb_strlen($normalizedKeyword) < self::MIN_TERM_LENGTH || isset($scoredKeywords[$normalizedKeyword])) {
                continue;
            }

            $scoredKeywords[$normalizedKeyword] = true;

            if (str_contains($candidateTitle, $normalizedKeyword)) {
                $score += 5;
            }

            if (str_contains($candidateDescription, $normalizedKeyword)) {
                $score += 3;
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
                $normalizedTerm = $this->normalizeText($term);
                $termKey = $this->normalizeTermKey($normalizedTerm);

                if (
                    mb_strlen($normalizedTerm) < self::MIN_TERM_LENGTH
                    || isset(self::STOP_WORDS[$termKey])
                    || isset($terms[$normalizedTerm])
                ) {
                    continue;
                }

                $terms[$normalizedTerm] = true;

                if (self::MAX_TERMS === count($terms)) {
                    break 2;
                }
            }
        }

        return array_keys($terms);
    }

    /**
     * @param array<array-key, mixed> $keywords
     *
     * @return array{keywords: list<string>, terms: list<string>}
     */
    private function extractSignals(array $keywords, string $title, string $description): array
    {
        $keywordSignals = [];

        foreach ($keywords as $keyword) {
            if (!is_string($keyword)) {
                continue;
            }

            $normalizedKeyword = $this->normalizeText($keyword);

            if (mb_strlen($normalizedKeyword) < self::MIN_TERM_LENGTH || isset($keywordSignals[$normalizedKeyword])) {
                continue;
            }

            $keywordSignals[$normalizedKeyword] = true;

            if (self::MAX_KEYWORDS === count($keywordSignals)) {
                break;
            }
        }

        return [
            'keywords' => array_keys($keywordSignals),
            'terms' => $this->extractTerms([
                ...array_keys($keywordSignals),
                $title,
                $description,
            ]),
        ];
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private function mergeSignalsForPrefilter(array $keywords, array $terms): array
    {
        $signals = [];

        foreach ([...$keywords, ...$terms] as $signal) {
            $normalizedSignal = $this->normalizeText($signal);

            if ('' === $normalizedSignal || isset($signals[$normalizedSignal])) {
                continue;
            }

            $signals[$normalizedSignal] = true;

            if (self::MAX_TERMS === count($signals)) {
                break;
            }
        }

        return array_keys($signals);
    }

    private function normalizeTermKey(string $value): string
    {
        return strtr($value, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'á' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'ç' => 'c',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ñ' => 'n',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'õ' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ú' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
        ]);
    }

    private function normalizeText(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($value ?? '')) ?? '', ' ');
    }
}
