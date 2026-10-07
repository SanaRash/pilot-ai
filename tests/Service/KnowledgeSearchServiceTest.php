<?php

declare(strict_types=1);

use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\KnowledgeArticle;
use App\Entity\Ticket;
use App\Repository\AIAnalysisRepository;
use App\Repository\KnowledgeArticleRepository;
use App\Service\KnowledgeSearchService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureKnowledgeSearch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function knowledgeArticle(string $title, array $keywords = [], bool $active = true, bool $clientSafe = true): KnowledgeArticle
{
    return (new KnowledgeArticle($title, $title.' procédure de support', $keywords))
        ->setIsActive($active)
        ->setIsClientSafe($clientSafe);
}

$category = (new Category())->setName('Réseau');
$categoryArticle = knowledgeArticle('Diagnostic réseau');
$categoryArticle->setCategory($category);
$keywordArticle = knowledgeArticle('Accès sans fil', ['wifi']);
$literalArticle = knowledgeArticle('Connexion imprimante', ['papier']);
$aiOnlyArticle = knowledgeArticle('Échec écran noir', ['affichage']);
$inactiveArticle = knowledgeArticle('Wi-Fi ancien', ['wifi'], false);
$internalArticle = knowledgeArticle('Wi-Fi interne', ['wifi'], true, false);
$articles = [
    $categoryArticle,
    $keywordArticle,
    $literalArticle,
    $aiOnlyArticle,
    $inactiveArticle,
    $internalArticle,
];

$articleRepository = new class($articles) extends KnowledgeArticleRepository {
    public function __construct(private readonly array $articles)
    {
    }

    public function findClientSafeActive(): array
    {
        return array_values(array_filter(
            $this->articles,
            static fn (KnowledgeArticle $article): bool => $article->isActive() && $article->isClientSafe(),
        ));
    }
};
$analysis = (new AIAnalysis())->setKeywords(['écran'])->setCreatedAt(new DateTimeImmutable());
$analysisRepository = new class($analysis) extends AIAnalysisRepository {
    public function __construct(private readonly AIAnalysis $analysis)
    {
    }

    public function findLatestForTicket(Ticket $ticket): ?AIAnalysis
    {
        return $this->analysis;
    }
};
$ticket = (new Ticket())
    ->setTitle('Accès réseau')
    ->setDescription('Description')
    ->setStatus(Ticket::STATUS_OPEN)
    ->setPriority(Ticket::PRIORITY_MEDIUM)
    ->setSource('APP')
    ->setCreatedAt(new DateTimeImmutable())
    ->setCategory($category);

$search = new KnowledgeSearchService($articleRepository, $analysisRepository);
$results = $search->search('La connexion wifi et imprimante écran.', $ticket);

ensureKnowledgeSearch(3 === count($results), 'Knowledge search must return at most three articles.');
ensureKnowledgeSearch(
    [$categoryArticle, $keywordArticle, $literalArticle] === $results,
    'Category, article keywords, and literal matches must rank deterministically.',
);
ensureKnowledgeSearch(
    [$categoryArticle, $aiOnlyArticle] === $search->search('écran', $ticket),
    'The latest analysis keywords may be used as a lower-priority relevance signal.',
);
ensureKnowledgeSearch(!in_array($inactiveArticle, $results, true), 'Inactive articles must not be returned.');
ensureKnowledgeSearch(!in_array($internalArticle, $results, true), 'Non-client-safe articles must not be returned.');

$globalResults = $search->search('wifi imprimante écran');
ensureKnowledgeSearch(
    [$keywordArticle, $literalArticle, $aiOnlyArticle] === $globalResults,
    'Global knowledge search must use message terms and article keywords without requiring ticket context.',
);
ensureKnowledgeSearch(3 === count($globalResults), 'Global knowledge search must return at most three articles.');

echo "KnowledgeSearchServiceTest passed.\n";
