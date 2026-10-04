<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\CategoryRepository;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class StubAIProvider implements AIProviderInterface
{
    public int $callCount = 0;
    public ?AIAnalysisInput $receivedInput = null;

    public function __construct(
        private readonly ?AIAnalysisResult $result = null,
        private readonly ?AIProviderException $exception = null,
    ) {
    }

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        ++$this->callCount;
        $this->receivedInput = $input;

        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->result ?? throw new RuntimeException('The test stub has no configured result.');
    }
}

final class SpyEntityManager extends EntityManagerDecorator
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushCount = 0;
    public bool $failOnFlush = false;

    public function persist(object $object): void
    {
        $this->persisted[] = $object;
    }

    public function flush(): void
    {
        ++$this->flushCount;

        if ($this->failOnFlush) {
            throw new RuntimeException('Simulated flush failure.');
        }
    }
}

function serviceWithPersistenceSpy(StubAIProvider $provider, EntityManagerInterface $entityManager): array
{
    global $categoryRepository;

    $spy = new SpyEntityManager($entityManager);

    return [new AIService($provider, $spy, $categoryRepository), $spy];
}

function ensureService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function validTicket(): Ticket
{
    return (new Ticket())
        ->setTitle('Impossible de se connecter')
        ->setDescription('Une erreur apparaît après la saisie du mot de passe.')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('WEB')
        ->setCreatedAt(new DateTimeImmutable('2026-09-19 10:00:00'));
}

function validServiceResult(): AIAnalysisResult
{
    return new AIAnalysisResult(
        summary: 'Le client ne parvient pas à se connecter.',
        suggestedPriority: Ticket::PRIORITY_HIGH,
        suggestedCategory: 'Support',
        keywords: ['connexion', 'mot de passe'],
        suggestions: ['Vérifier les journaux d’authentification.'],
    );
}

function expectValidationException(callable $callback, string $scenario): void
{
    try {
        $callback();
    } catch (AIValidationException) {
        return;
    }

    throw new RuntimeException(sprintf('Expected AIValidationException for %s.', $scenario));
}

function analyzeInvalidResult(AIAnalysisResult $result, string $scenario): void
{
    global $entityManager;

    $provider = new StubAIProvider($result);
    $ticket = validTicket();
    $ticketBefore = serialize($ticket);
    [$service, $persistenceSpy] = serviceWithPersistenceSpy($provider, $entityManager);

    expectValidationException(
        fn () => $service->analyzeTicket($ticket),
        $scenario,
    );

    ensureService(1 === $provider->callCount, sprintf('Provider call count mismatch for %s.', $scenario));
    ensureService([] === $persistenceSpy->persisted, sprintf('Persistence occurred for %s.', $scenario));
    ensureService(0 === $persistenceSpy->flushCount, sprintf('Flush occurred for %s.', $scenario));
    ensureService($ticketBefore === serialize($ticket), sprintf('Ticket mutated for %s.', $scenario));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
ensureService($entityManager instanceof EntityManagerInterface, 'Doctrine did not provide an ORM entity manager.');
$categoryRepository = $entityManager->getRepository(Category::class);
ensureService($categoryRepository instanceof CategoryRepository, 'Category repository is unavailable.');
$connection = $entityManager->getConnection();
$connection->beginTransaction();
$boundaryCategory = str_repeat('é', 100);
foreach (['Support', 'Catégorie valide', $boundaryCategory] as $categoryName) {
    $entityManager->persist((new Category())->setName($categoryName));
}
$entityManager->flush();

$ticket = validTicket();
$ticketBefore = serialize($ticket);
$expectedResult = validServiceResult();
$provider = new StubAIProvider($expectedResult);
[$service, $persistenceSpy] = serviceWithPersistenceSpy($provider, $entityManager);
$beforeAnalysis = new DateTimeImmutable();
$actualResult = $service->analyzeTicket($ticket);
$afterAnalysis = new DateTimeImmutable();

ensureService($expectedResult === $actualResult, 'AIService must return the exact provider result.');
ensureService(1 === $provider->callCount, 'Provider must be called exactly once.');
ensureService(Ticket::PRIORITY_HIGH === $actualResult->suggestedPriority, 'Suggested priority must be returned unchanged.');
ensureService('Support' === $actualResult->suggestedCategory, 'An allowed suggested category must be returned unchanged.');
ensureService(['connexion', 'mot de passe'] === $actualResult->keywords, 'Keywords must be returned unchanged and in the same order.');
ensureService(['Vérifier les journaux d’authentification.'] === $actualResult->suggestions, 'Suggestions must be returned unchanged and in the same order.');
ensureService('Impossible de se connecter' === $provider->receivedInput?->title, 'Unexpected title sent to provider.');
ensureService('Une erreur apparaît après la saisie du mot de passe.' === $provider->receivedInput?->description, 'Unexpected description sent to provider.');
ensureService(in_array('Support', $provider->receivedInput?->allowedCategories ?? [], true), 'Existing category names must be passed to the provider.');
ensureService(Ticket::PRIORITY_MEDIUM === $ticket->getPriority(), 'Ticket priority must remain unchanged.');
ensureService(null === $ticket->getCategory(), 'Ticket category must remain unchanged.');
ensureService($ticketBefore === serialize($ticket), 'Ticket was mutated after a successful analysis.');
ensureService(1 === count($persistenceSpy->persisted), 'Exactly one entity must be persisted for a successful analysis.');
ensureService(1 === $persistenceSpy->flushCount, 'Exactly one flush must occur for a successful analysis.');
$persistedAnalysis = $persistenceSpy->persisted[0];
ensureService($persistedAnalysis instanceof AIAnalysis, 'The persisted entity must be an AIAnalysis.');
ensureService($expectedResult->summary === $persistedAnalysis->getSummary(), 'Persisted summary mismatch.');
ensureService($expectedResult->suggestedPriority === $persistedAnalysis->getSuggestedPriority(), 'Persisted priority mismatch.');
ensureService($expectedResult->suggestedCategory === $persistedAnalysis->getSuggestedCategory(), 'Persisted category mismatch.');
ensureService($expectedResult->keywords === $persistedAnalysis->getKeywords(), 'Persisted keywords mismatch.');
ensureService($expectedResult->suggestions === $persistedAnalysis->getSuggestions(), 'Persisted suggestions mismatch.');
ensureService($ticket === $persistedAnalysis->getTicket(), 'Persisted ticket mismatch.');
ensureService($persistedAnalysis->getCreatedAt() >= $beforeAnalysis, 'Persisted creation date is too early.');
ensureService($persistedAnalysis->getCreatedAt() <= $afterAnalysis, 'Persisted creation date is too late.');

$validBoundaryResult = new AIAnalysisResult(
    summary: str_repeat('é', 2_000),
    suggestedPriority: Ticket::PRIORITY_HIGH,
    suggestedCategory: $boundaryCategory,
    keywords: array_map(
        static fn (int $index): string => str_repeat('é', 97).sprintf('%03d', $index),
        range(1, 20),
    ),
    suggestions: array_map(
        static fn (int $index): string => str_repeat('é', 997).sprintf('%03d', $index),
        range(1, 10),
    ),
);
$boundaryProvider = new StubAIProvider($validBoundaryResult);
[$boundaryService, $boundaryPersistenceSpy] = serviceWithPersistenceSpy($boundaryProvider, $entityManager);
ensureService(
    $validBoundaryResult === $boundaryService->analyzeTicket(validTicket()),
    'Valid UTF-8 boundaries must be accepted.',
);
ensureService(1 === count($boundaryPersistenceSpy->persisted), 'Boundary analysis must be persisted once.');
ensureService(1 === $boundaryPersistenceSpy->flushCount, 'Boundary analysis must be flushed once.');
ensureService(in_array($boundaryCategory, $boundaryProvider->receivedInput?->allowedCategories ?? [], true), 'Allowed categories must be supplied as analysis context.');

$invalidInputTickets = [
    'null title' => (new Ticket())->setDescription('Description valide'),
    'blank title' => (new Ticket())->setTitle(" \t\n")->setDescription('Description valide'),
    'null description' => (new Ticket())->setTitle('Titre valide'),
    'blank description' => (new Ticket())->setTitle('Titre valide')->setDescription(" \t\n"),
];

foreach ($invalidInputTickets as $scenario => $invalidTicket) {
    $invalidInputProvider = new StubAIProvider(validServiceResult());
    $invalidTicketBefore = serialize($invalidTicket);
    [$invalidInputService, $invalidInputPersistenceSpy] = serviceWithPersistenceSpy($invalidInputProvider, $entityManager);

    expectValidationException(
        fn () => $invalidInputService->analyzeTicket($invalidTicket),
        $scenario,
    );

    ensureService(0 === $invalidInputProvider->callCount, sprintf('Provider called for %s.', $scenario));
    ensureService([] === $invalidInputPersistenceSpy->persisted, sprintf('Persistence occurred for %s.', $scenario));
    ensureService(0 === $invalidInputPersistenceSpy->flushCount, sprintf('Flush occurred for %s.', $scenario));
    ensureService($invalidTicketBefore === serialize($invalidTicket), sprintf('Ticket mutated for %s.', $scenario));
}

foreach ([Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT] as $allowedPriority) {
    $allowedResult = new AIAnalysisResult('Résumé valide', $allowedPriority, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']);
    $allowedProvider = new StubAIProvider($allowedResult);
    [$allowedService, $allowedPersistenceSpy] = serviceWithPersistenceSpy($allowedProvider, $entityManager);

    ensureService(
        $allowedResult === $allowedService->analyzeTicket(validTicket()),
        sprintf('Allowed priority %s was not returned unchanged.', $allowedPriority),
    );
    ensureService(1 === count($allowedPersistenceSpy->persisted), sprintf('Allowed priority %s was not persisted once.', $allowedPriority));
    ensureService(1 === $allowedPersistenceSpy->flushCount, sprintf('Allowed priority %s was not flushed once.', $allowedPriority));
}

foreach ([null, '', 'CRITICAL', 'high'] as $invalidPriority) {
    analyzeInvalidResult(new AIAnalysisResult('Résumé valide', $invalidPriority, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'invalid priority');
}

analyzeInvalidResult(new AIAnalysisResult(null, Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'null summary');
analyzeInvalidResult(new AIAnalysisResult('', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'empty summary');
analyzeInvalidResult(new AIAnalysisResult(" \t\n", Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'ASCII blank summary');
analyzeInvalidResult(new AIAnalysisResult("\u{00A0}\u{2003}", Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'Unicode blank summary');
analyzeInvalidResult(new AIAnalysisResult(str_repeat('é', 2_001), Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'oversized summary');
foreach ([null, '', " \t\n", "\u{00A0}\u{2003}", 'Valeur hors liste'] as $unavailableCategory) {
    $categoryResult = new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, $unavailableCategory, ['mot-clé'], ['Suggestion valide']);
    $categoryProvider = new StubAIProvider($categoryResult);
    [$categoryService, $categoryPersistenceSpy] = serviceWithPersistenceSpy($categoryProvider, $entityManager);
    $sanitizedResult = $categoryService->analyzeTicket(validTicket());

    ensureService(null === $sanitizedResult->suggestedCategory, 'Null, blank, or out-of-list category suggestions must be removed.');
    ensureService(Ticket::PRIORITY_HIGH === $sanitizedResult->suggestedPriority, 'Category sanitization must not affect suggested priority.');
    ensureService(1 === $categoryProvider->callCount, 'Category sanitization must not add an AI call.');
    ensureService(1 === $categoryPersistenceSpy->flushCount, 'A categoryless analysis must still be persisted.');
    ensureService(null === $categoryPersistenceSpy->persisted[0]->getSuggestedCategory(), 'Sanitized category must be persisted as null.');
}
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, str_repeat('é', 101), ['mot-clé'], ['Suggestion valide']), 'oversized category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', null, ['Suggestion valide']), 'null keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [], ['Suggestion valide']), 'empty keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [''], ['Suggestion valide']), 'empty keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [" \t\n"], ['Suggestion valide']), 'ASCII blank keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ["\u{00A0}\u{2003}"], ['Suggestion valide']), 'Unicode blank keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['duplicate', 'duplicate'], ['Suggestion valide']), 'duplicate keywords');
analyzeInvalidResult(new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    array_map(static fn (int $index): string => 'keyword-'.$index, range(1, 21)),
    ['Suggestion valide'],
), 'too many keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [str_repeat('é', 101)], ['Suggestion valide']), 'oversized keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['valid', 42], ['Suggestion valide']), 'non-string keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['key' => 'value'], ['Suggestion valide']), 'non-list keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], null), 'null suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], []), 'empty suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['']), 'empty suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], [" \t\n"]), 'ASCII blank suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ["\u{00A0}\u{2003}"]), 'Unicode blank suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['duplicate', 'duplicate']), 'duplicate suggestions');
analyzeInvalidResult(new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    ['mot-clé'],
    array_map(static fn (int $index): string => 'Suggestion '.$index, range(1, 11)),
), 'too many suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], [str_repeat('é', 1_001)]), 'oversized suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['valid', 42]), 'non-string suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['key' => 'value']), 'non-list suggestions');

$caseDistinctResult = new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    ['Erreur', 'erreur'],
    ['Vérifier le service', 'vérifier le service'],
);
$caseDistinctProvider = new StubAIProvider($caseDistinctResult);
[$caseDistinctService, $caseDistinctPersistenceSpy] = serviceWithPersistenceSpy($caseDistinctProvider, $entityManager);
ensureService(
    $caseDistinctResult === $caseDistinctService->analyzeTicket(validTicket()),
    'Strictly distinct keyword and suggestion casing must be accepted unchanged.',
);
ensureService(1 === count($caseDistinctPersistenceSpy->persisted), 'Case-distinct result must be persisted once.');

$historyProvider = new StubAIProvider(validServiceResult());
[$historyService, $historyPersistenceSpy] = serviceWithPersistenceSpy($historyProvider, $entityManager);
$historyTicket = validTicket();
$historyService->analyzeTicket($historyTicket);
$historyService->analyzeTicket($historyTicket);
ensureService(2 === $historyProvider->callCount, 'Provider must be called once per requested analysis.');
ensureService(2 === count($historyPersistenceSpy->persisted), 'Each successful analysis must create a persisted entity.');
ensureService(2 === $historyPersistenceSpy->flushCount, 'Each successful analysis must be flushed once.');
ensureService($historyPersistenceSpy->persisted[0] !== $historyPersistenceSpy->persisted[1], 'Successful analyses must create distinct entities.');
ensureService($historyTicket === $historyPersistenceSpy->persisted[0]->getTicket(), 'First analysis ticket mismatch.');
ensureService($historyTicket === $historyPersistenceSpy->persisted[1]->getTicket(), 'Second analysis ticket mismatch.');

$providerException = new AIProviderException('Provider unavailable');
$failingProvider = new StubAIProvider(exception: $providerException);
$failingTicket = validTicket();
$failingTicketBefore = serialize($failingTicket);
[$failingService, $failingPersistenceSpy] = serviceWithPersistenceSpy($failingProvider, $entityManager);

try {
    $failingService->analyzeTicket($failingTicket);
    throw new RuntimeException('Expected provider exception was not thrown.');
} catch (AIProviderException $caughtException) {
    ensureService($providerException === $caughtException, 'AIProviderException must propagate unchanged.');
}

ensureService(1 === $failingProvider->callCount, 'Provider must not be retried after an error.');
ensureService([] === $failingPersistenceSpy->persisted, 'Provider failure must not persist an analysis.');
ensureService(0 === $failingPersistenceSpy->flushCount, 'Provider failure must not flush.');
ensureService($failingTicketBefore === serialize($failingTicket), 'Ticket was mutated after a provider error.');

$flushFailureProvider = new StubAIProvider(validServiceResult());
$flushFailureTicket = validTicket();
$flushFailureTicketBefore = serialize($flushFailureTicket);
[$flushFailureService, $flushFailurePersistenceSpy] = serviceWithPersistenceSpy($flushFailureProvider, $entityManager);
$flushFailurePersistenceSpy->failOnFlush = true;

try {
    $flushFailureService->analyzeTicket($flushFailureTicket);
    throw new RuntimeException('Expected flush exception was not thrown.');
} catch (RuntimeException $exception) {
    ensureService('Simulated flush failure.' === $exception->getMessage(), 'Flush exception must propagate unchanged.');
}

ensureService(1 === $flushFailureProvider->callCount, 'Provider must be called once before a flush failure.');
ensureService(1 === count($flushFailurePersistenceSpy->persisted), 'A validated analysis must be scheduled before flush.');
ensureService(1 === $flushFailurePersistenceSpy->flushCount, 'A failing flush must be attempted exactly once.');
ensureService($flushFailureTicketBefore === serialize($flushFailureTicket), 'Ticket was mutated after a flush failure.');

$connection = $entityManager->getConnection();
$databaseUserEmail = 'ai-analysis-test-'.bin2hex(random_bytes(8)).'@example.test';
$databaseTicketId = null;
$databaseAnalysisIds = [];
$connection->beginTransaction();

try {
    $databaseUser = (new User())
        ->setEmail($databaseUserEmail)
        ->setRoles(['ROLE_CLIENT'])
        ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT))
        ->setFirstname('AI')
        ->setLastname('Test')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable());
    $databaseTicket = validTicket()->setCreatedBy($databaseUser);

    $entityManager->persist($databaseUser);
    $entityManager->persist($databaseTicket);
    $entityManager->flush();

    $databaseTicketBefore = serialize($databaseTicket);
    $databaseProvider = new StubAIProvider(validServiceResult());
    $databaseService = new AIService($databaseProvider, $entityManager, $categoryRepository);
    $databaseService->analyzeTicket($databaseTicket);
    $databaseService->analyzeTicket($databaseTicket);

    $storedAnalyses = $entityManager->getRepository(AIAnalysis::class)->findBy(
        ['ticket' => $databaseTicket],
        ['id' => 'ASC'],
    );

    ensureService(2 === count($storedAnalyses), 'Two successful analyses must create two database rows.');
    ensureService($storedAnalyses[0] !== $storedAnalyses[1], 'Stored analyses must remain distinct.');
    ensureService($databaseTicket->getId() === $storedAnalyses[0]->getTicket()?->getId(), 'First stored ticket_id mismatch.');
    ensureService($databaseTicket->getId() === $storedAnalyses[1]->getTicket()?->getId(), 'Second stored ticket_id mismatch.');
    ensureService(validServiceResult()->summary === $storedAnalyses[0]->getSummary(), 'Stored database summary mismatch.');
    ensureService(validServiceResult()->keywords === $storedAnalyses[0]->getKeywords(), 'Stored database keyword order mismatch.');
    ensureService(validServiceResult()->suggestions === $storedAnalyses[0]->getSuggestions(), 'Stored database suggestion order mismatch.');
    ensureService(2 === $databaseProvider->callCount, 'Database provider must be called once per analysis.');
    ensureService($databaseTicketBefore === serialize($databaseTicket), 'Database ticket was mutated by analysis persistence.');
    $databaseTicketId = $databaseTicket->getId();
    $databaseAnalysisIds = array_map(static fn (AIAnalysis $analysis): ?int => $analysis->getId(), $storedAnalyses);
} finally {
    $connection->rollBack();
    $entityManager->clear();
}

ensureService(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE email = ?', [$databaseUserEmail]), 'Temporary database user survived rollback.');
ensureService(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket WHERE id = ?', [$databaseTicketId]), 'Temporary database ticket survived rollback.');
foreach ($databaseAnalysisIds as $databaseAnalysisId) {
    ensureService(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis WHERE id = ?', [$databaseAnalysisId]), 'Temporary AIAnalysis survived rollback.');
}

$connection->rollBack();

echo "AIService tests: PASS\n";
