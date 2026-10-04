<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\Controller\TechnicianController;
use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use App\Repository\AIAnalysisRepository;
use App\Repository\CategoryRepository;
use App\Repository\InterventionRepository;
use App\Repository\TicketHistoryRepository;
use App\Repository\TicketRepository;
use App\Service\SimilarTicketFinder;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class FinalValidationProvider implements AIProviderInterface
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

        return $this->result ?? throw new RuntimeException('Final validation provider has no result.');
    }
}

final class FinalValidationFailingEntityManager extends EntityManagerDecorator
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushCount = 0;

    public function persist(object $object): void
    {
        $this->persisted[] = $object;
    }

    public function flush(): void
    {
        ++$this->flushCount;

        throw new RuntimeException('Simulated AIAnalysis persistence failure.');
    }
}

function ensureAIFinal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function finalValidationUser(string $email, array $roles, string $firstname, string $lastname): User
{
    return (new User())
        ->setEmail($email)
        ->setRoles($roles)
        ->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT))
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-09-01 09:00:00'));
}

function finalValidationTicket(
    User $client,
    string $title,
    string $description,
    DateTimeImmutable $createdAt,
    ?Category $category = null,
    ?User $assignedTo = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setCategory($category)
        ->setAssignedTo($assignedTo);
}

/**
 * @return array<string, mixed>
 */
function finalValidationTicketSnapshot(Ticket $ticket, int $historyCount, int $analysisCount): array
{
    return [
        'status' => $ticket->getStatus(),
        'priority' => $ticket->getPriority(),
        'category' => $ticket->getCategory()?->getId(),
        'assignedTo' => $ticket->getAssignedTo()?->getId(),
        'createdBy' => $ticket->getCreatedBy()?->getId(),
        'source' => $ticket->getSource(),
        'title' => $ticket->getTitle(),
        'description' => $ticket->getDescription(),
        'updatedAt' => $ticket->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        'historyCount' => $historyCount,
        'analysisCount' => $analysisCount,
    ];
}

function finalValidationAssertTicketSnapshot(
    Ticket $ticket,
    array $snapshot,
    int $historyCount,
    int $analysisCount,
    string $scenario,
): void {
    ensureAIFinal($snapshot['status'] === $ticket->getStatus(), $scenario.': ticket status was mutated.');
    ensureAIFinal($snapshot['priority'] === $ticket->getPriority(), $scenario.': ticket priority was mutated.');
    ensureAIFinal($snapshot['category'] === $ticket->getCategory()?->getId(), $scenario.': ticket category was mutated.');
    ensureAIFinal($snapshot['assignedTo'] === $ticket->getAssignedTo()?->getId(), $scenario.': ticket assignment was mutated.');
    ensureAIFinal($snapshot['createdBy'] === $ticket->getCreatedBy()?->getId(), $scenario.': ticket creator was mutated.');
    ensureAIFinal($snapshot['source'] === $ticket->getSource(), $scenario.': ticket source was mutated.');
    ensureAIFinal($snapshot['title'] === $ticket->getTitle(), $scenario.': ticket title was mutated.');
    ensureAIFinal($snapshot['description'] === $ticket->getDescription(), $scenario.': ticket description was mutated.');
    ensureAIFinal($snapshot['updatedAt'] === $ticket->getUpdatedAt()?->format(DateTimeInterface::ATOM), $scenario.': ticket updatedAt was mutated.');
    ensureAIFinal($snapshot['historyCount'] === $historyCount, $scenario.': AI flow created unexpected TicketHistory.');
    ensureAIFinal($analysisCount >= $snapshot['analysisCount'], $scenario.': AIAnalysis count decreased unexpectedly.');
}

function finalValidationResult(
    string $summary,
    string $priority,
    string $category,
    array $keywords,
    array $suggestions,
): AIAnalysisResult {
    return new AIAnalysisResult(
        summary: $summary,
        suggestedPriority: $priority,
        suggestedCategory: $category,
        keywords: $keywords,
        suggestions: $suggestions,
    );
}

function finalValidationCountAIAnalyses(AIAnalysisRepository $repository, Ticket $ticket): int
{
    return $repository->count(['ticket' => $ticket]);
}

function finalValidationCountTicketHistory(EntityManagerInterface $entityManager, Ticket $ticket): int
{
    return (int) $entityManager->getConnection()->fetchOne(
        'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = :ticket',
        ['ticket' => $ticket->getId()],
    );
}

function finalValidationRenderTechnicianTicketShow(
    TechnicianController $controller,
    TokenStorageInterface $tokenStorage,
    User $technician,
    Ticket $ticket,
    CategoryRepository $categoryRepository,
    InterventionRepository $interventionRepository,
    TicketHistoryRepository $ticketHistoryRepository,
    AIAnalysisRepository $aiAnalysisRepository,
): string {
    $tokenStorage->setToken(new UsernamePasswordToken($technician, 'main', $technician->getRoles()));

    $response = $controller->show(
        $ticket,
        $categoryRepository,
        $interventionRepository,
        $ticketHistoryRepository,
        $aiAnalysisRepository,
    );

    ensureAIFinal(Response::HTTP_OK === $response->getStatusCode(), 'Technician ticket detail must render with HTTP 200.');

    return $response->getContent();
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$entityManager = $container->get('doctrine')->getManager();
ensureAIFinal($entityManager instanceof EntityManagerInterface, 'Doctrine did not provide an ORM entity manager.');

$ticketRepository = $entityManager->getRepository(Ticket::class);
$aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);
$categoryRepository = $entityManager->getRepository(Category::class);
$interventionRepository = $entityManager->getRepository(Intervention::class);
$ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
ensureAIFinal($ticketRepository instanceof TicketRepository, 'Unexpected ticket repository.');
ensureAIFinal($aiAnalysisRepository instanceof AIAnalysisRepository, 'Unexpected AI analysis repository.');
ensureAIFinal($categoryRepository instanceof CategoryRepository, 'Unexpected category repository.');
ensureAIFinal($interventionRepository instanceof InterventionRepository, 'Unexpected intervention repository.');
ensureAIFinal($ticketHistoryRepository instanceof TicketHistoryRepository, 'Unexpected ticket history repository.');

$similarTicketFinder = new SimilarTicketFinder($ticketRepository, $aiAnalysisRepository);

$controller = $container->get(TechnicianController::class);
ensureAIFinal($controller instanceof TechnicianController, 'TechnicianController service is unavailable.');
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
$controllerContainer = $controllerContainerProperty->getValue($controller);
ensureAIFinal($controllerContainer instanceof ContainerInterface, 'Controller container is unavailable.');
$tokenStorage = $controllerContainer->get('security.token_storage');
$requestStack = $controllerContainer->get('request_stack');
ensureAIFinal($tokenStorage instanceof TokenStorageInterface, 'Token storage is unavailable.');
ensureAIFinal($requestStack instanceof RequestStack, 'Request stack is unavailable.');

$connection = $entityManager->getConnection();
$connection->beginTransaction();
$request = Request::create('/technician/tickets/1', 'GET');
$request->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($request);

try {
    $client = finalValidationUser('ai-final-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'IA');
    $technician = finalValidationUser('ai-final-tech-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'IA');
    $category = (new Category())->setName('Support IA '.bin2hex(random_bytes(4)));
    $otherCategory = (new Category())->setName('Autre IA '.bin2hex(random_bytes(4)));

    foreach ([$client, $technician, $category, $otherCategory] as $entity) {
        $entityManager->persist($entity);
    }

    $ticket = finalValidationTicket(
        $client,
        'Zorblaxq7ventilation',
        'Vextorkryptonitebrumel',
        new DateTimeImmutable('2026-09-20 10:00:00'),
        $category,
        $technician,
    );
    $exactTitleCandidate = finalValidationTicket(
        $client,
        'zorblaxq7ventilation',
        'Incident indépendant.',
        new DateTimeImmutable('2026-09-20 11:00:00'),
        $otherCategory,
    );
    $latestKeywordCandidate = finalValidationTicket(
        $client,
        'Routeur quelconque',
        'Signal laserjetunique détecté sur le poste.',
        new DateTimeImmutable('2026-09-20 12:00:00'),
        $otherCategory,
    );
    $oldKeywordCandidate = finalValidationTicket(
        $client,
        'Ancienne piste',
        'La trace ancienmotunique apparaît.',
        new DateTimeImmutable('2026-09-20 13:00:00'),
        $otherCategory,
    );
    $categoryCandidate = finalValidationTicket(
        $client,
        'Sujet sans lien lexical',
        'Description sans lien lexical',
        new DateTimeImmutable('2026-09-20 14:00:00'),
        $category,
    );
    $unrelatedCandidate = finalValidationTicket(
        $client,
        'Écran brisé',
        'Dalle fissurée',
        new DateTimeImmutable('2026-09-20 15:00:00'),
        $otherCategory,
    );

    foreach ([$ticket, $exactTitleCandidate, $latestKeywordCandidate, $oldKeywordCandidate, $categoryCandidate, $unrelatedCandidate] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $initialHistoryCount = finalValidationCountTicketHistory($entityManager, $ticket);
    $initialAnalysisCount = finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket);
    $initialSnapshot = finalValidationTicketSnapshot($ticket, $initialHistoryCount, $initialAnalysisCount);

    $firstResult = finalValidationResult(
        'Résumé final de la panne authentification.',
        Ticket::PRIORITY_HIGH,
        (string) $category->getName(),
        ['ancienmotunique', 'vextorkryptonitebrumel'],
        ['Vérifier les journaux applicatifs.', 'Contacter le client pour confirmer le navigateur.'],
    );
    $firstProvider = new FinalValidationProvider($firstResult);
    $aiService = new AIService($firstProvider, $entityManager, $categoryRepository);
    $firstBefore = new DateTimeImmutable();
    $returnedFirstResult = $aiService->analyzeTicket($ticket);
    $firstAfter = new DateTimeImmutable();

    ensureAIFinal($firstResult === $returnedFirstResult, 'AIService must return the exact valid provider result.');
    ensureAIFinal(1 === $firstProvider->callCount, 'Valid provider must be called exactly once.');
    ensureAIFinal('Zorblaxq7ventilation' === $firstProvider->receivedInput?->title, 'Provider must receive only the ticket title.');
    ensureAIFinal('Vextorkryptonitebrumel' === $firstProvider->receivedInput?->description, 'Provider must receive only the ticket description.');

    $entityManager->refresh($ticket);
    ensureAIFinal(1 === finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket), 'A valid AI result must create exactly one AIAnalysis.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'valid AI analysis',
    );

    $persistedAnalyses = $aiAnalysisRepository->findBy(['ticket' => $ticket], ['createdAt' => 'ASC', 'id' => 'ASC']);
    ensureAIFinal(1 === count($persistedAnalyses), 'Exactly one persisted AIAnalysis must be loaded after the first analysis.');
    $firstAnalysis = $persistedAnalyses[0];
    ensureAIFinal($firstAnalysis instanceof AIAnalysis, 'First persisted analysis must be an AIAnalysis.');
    ensureAIFinal($firstResult->summary === $firstAnalysis->getSummary(), 'Persisted summary mismatch.');
    ensureAIFinal($firstResult->suggestedPriority === $firstAnalysis->getSuggestedPriority(), 'Persisted suggested priority mismatch.');
    ensureAIFinal($firstResult->suggestedCategory === $firstAnalysis->getSuggestedCategory(), 'Persisted suggested category mismatch.');
    ensureAIFinal($firstResult->keywords === $firstAnalysis->getKeywords(), 'Persisted keywords must keep the same order.');
    ensureAIFinal($firstResult->suggestions === $firstAnalysis->getSuggestions(), 'Persisted suggestions must keep the same order.');
    ensureAIFinal($ticket === $firstAnalysis->getTicket(), 'Persisted AIAnalysis must be linked to the server-side ticket.');
    ensureAIFinal($firstAnalysis->getCreatedAt() >= $firstBefore, 'AIAnalysis createdAt must be generated server-side after analysis starts.');
    ensureAIFinal($firstAnalysis->getCreatedAt() <= $firstAfter, 'AIAnalysis createdAt must be generated server-side before analysis ends.');

    $secondResult = finalValidationResult(
        'Deuxième résumé final différent.',
        Ticket::PRIORITY_URGENT,
        (string) $otherCategory->getName(),
        ['laserjetunique', 'q7ventilationunique'],
        ['Escalader au support infrastructure.'],
    );
    $secondProvider = new FinalValidationProvider($secondResult);
    $secondService = new AIService($secondProvider, $entityManager, $categoryRepository);
    $returnedSecondResult = $secondService->analyzeTicket($ticket);

    ensureAIFinal($secondResult === $returnedSecondResult, 'Second valid analysis must return the provider result unchanged.');
    ensureAIFinal(1 === $secondProvider->callCount, 'Second provider must be called exactly once.');
    $entityManager->refresh($ticket);
    ensureAIFinal(2 === finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket), 'A second valid analysis must create a second AIAnalysis.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'second AI analysis',
    );

    $allAnalyses = $aiAnalysisRepository->findBy(['ticket' => $ticket], ['createdAt' => 'ASC', 'id' => 'ASC']);
    ensureAIFinal(2 === count($allAnalyses), 'Both persisted analyses must remain linked to the ticket.');
    ensureAIFinal($firstResult->summary === $allAnalyses[0]->getSummary(), 'The first analysis must not be replaced.');
    ensureAIFinal($secondResult->summary === $allAnalyses[1]->getSummary(), 'The second analysis must be persisted separately.');
    ensureAIFinal($ticket === $allAnalyses[0]->getTicket() && $ticket === $allAnalyses[1]->getTicket(), 'Both analyses must remain linked to the same ticket.');

    $providerFailure = new FinalValidationProvider(exception: new AIProviderException('Provider unavailable for final validation.'));
    $providerFailureService = new AIService($providerFailure, $entityManager, $categoryRepository);
    try {
        $providerFailureService->analyzeTicket($ticket);
        throw new RuntimeException('AIProviderException was not propagated.');
    } catch (AIProviderException $exception) {
        ensureAIFinal('Provider unavailable for final validation.' === $exception->getMessage(), 'AIProviderException must be propagated intact.');
    }
    $entityManager->refresh($ticket);
    ensureAIFinal(1 === $providerFailure->callCount, 'Provider failure scenario must call the provider exactly once.');
    ensureAIFinal(2 === finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket), 'Provider failure must not create AIAnalysis.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'AIProviderException',
    );

    $invalidResultProvider = new FinalValidationProvider(finalValidationResult(
        'Résumé invalide car priorité incorrecte.',
        'CRITICAL',
        (string) $category->getName(),
        ['mot-cle'],
        ['Suggestion valide'],
    ));
    $invalidResultService = new AIService($invalidResultProvider, $entityManager, $categoryRepository);
    try {
        $invalidResultService->analyzeTicket($ticket);
        throw new RuntimeException('AIValidationException was not thrown for an invalid provider result.');
    } catch (AIValidationException) {
    }
    $entityManager->refresh($ticket);
    ensureAIFinal(1 === $invalidResultProvider->callCount, 'Invalid result scenario must call the provider exactly once.');
    ensureAIFinal(2 === finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket), 'Invalid result must not create AIAnalysis.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'AIValidationException',
    );

    $flushFailureProvider = new FinalValidationProvider(finalValidationResult(
        'Résumé avant échec de persistance.',
        Ticket::PRIORITY_LOW,
        (string) $category->getName(),
        ['persistance'],
        ['Suggestion avant échec'],
    ));
    $failingEntityManager = new FinalValidationFailingEntityManager($entityManager);
    $flushFailureService = new AIService($flushFailureProvider, $failingEntityManager, $categoryRepository);
    try {
        $flushFailureService->analyzeTicket($ticket);
        throw new RuntimeException('Persistence failure was not propagated.');
    } catch (RuntimeException $exception) {
        ensureAIFinal('Simulated AIAnalysis persistence failure.' === $exception->getMessage(), 'Persistence failure must be propagated unchanged.');
    }
    $entityManager->refresh($ticket);
    ensureAIFinal(1 === $flushFailureProvider->callCount, 'Flush failure scenario must call the provider exactly once.');
    ensureAIFinal(1 === count($failingEntityManager->persisted), 'Flush failure scenario must schedule exactly one AIAnalysis.');
    ensureAIFinal(1 === $failingEntityManager->flushCount, 'Flush failure scenario must attempt exactly one flush.');
    ensureAIFinal(2 === finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket), 'Flush failure must not create a committed AIAnalysis.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'AIAnalysis persistence failure',
    );

    $latestAnalysis = $aiAnalysisRepository->findLatestForTicket($ticket);
    ensureAIFinal($latestAnalysis instanceof AIAnalysis, 'Latest AIAnalysis must be available.');
    ensureAIFinal($secondResult->summary === $latestAnalysis->getSummary(), 'SimilarTicketFinder must be able to rely on the latest persisted AIAnalysis.');
    ensureAIFinal(['laserjetunique', 'q7ventilationunique'] === $latestAnalysis->getKeywords(), 'Latest AIAnalysis keywords must be the second analysis keywords.');

    $ticketBeforeSimilarity = finalValidationTicketSnapshot(
        $ticket,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
    );
    $similarTickets = $similarTicketFinder->findSimilar($ticket, $technician);
    ensureAIFinal(count($similarTickets) <= 5, 'SimilarTicketFinder must return at most five tickets.');
    ensureAIFinal(!in_array($ticket, $similarTickets, true), 'SimilarTicketFinder must exclude the current ticket.');
    ensureAIFinal(in_array($exactTitleCandidate, $similarTickets, true), 'Exact title candidate must be returned.');
    ensureAIFinal(
        in_array($latestKeywordCandidate, $similarTickets, true),
        sprintf(
            'Latest AI keyword candidate must be returned (candidate #%d; returned: %s).',
            $latestKeywordCandidate->getId(),
            implode(', ', array_map(static fn (Ticket $similarTicket): string => (string) $similarTicket->getId(), $similarTickets)),
        ),
    );
    ensureAIFinal(!in_array($oldKeywordCandidate, $similarTickets, true), 'Old AI keyword candidate must not be returned from the latest analysis keywords.');
    ensureAIFinal(in_array($categoryCandidate, $similarTickets, true), 'Same category candidate must be returned.');
    ensureAIFinal(!in_array($unrelatedCandidate, $similarTickets, true), 'Zero-score candidate must be excluded.');
    ensureAIFinal($exactTitleCandidate === $similarTickets[0], 'Similar tickets must be ordered deterministically by score DESC first.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $ticketBeforeSimilarity,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'SimilarTicketFinder',
    );

    $html = finalValidationRenderTechnicianTicketShow(
        $controller,
        $tokenStorage,
        $technician,
        $ticket,
        $categoryRepository,
        $interventionRepository,
        $ticketHistoryRepository,
        $aiAnalysisRepository,
    );
    ensureAIFinal(str_contains($html, 'Deuxième résumé final différent.'), 'Technician detail must display the latest AI summary.');
    ensureAIFinal(str_contains($html, 'Urgente'), 'Technician detail must display the suggested priority as a readable label.');
    ensureAIFinal(str_contains($html, (string) $otherCategory->getName()), 'Technician detail must display the suggested category.');
    ensureAIFinal(str_contains($html, 'laserjetunique'), 'Technician detail must display AI keywords.');
    ensureAIFinal(str_contains($html, 'Escalader au support infrastructure.'), 'Technician detail must display AI suggestions.');
    ensureAIFinal(str_contains($html, 'recommandations générées par l’IA'), 'Technician detail must state that AI results are assistive recommendations.');
    ensureAIFinal(str_contains($html, 'Routeur quelconque'), 'Technician detail must display similar tickets.');
    ensureAIFinal(!str_contains($html, '>Accepter<'), 'Technician detail must not expose an AI accept action.');
    ensureAIFinal(!str_contains($html, '>Appliquer<'), 'Technician detail must not expose an AI apply action.');
    ensureAIFinal(!str_contains($html, '>Générer<'), 'Technician detail must not expose an AI generation action.');
    finalValidationAssertTicketSnapshot(
        $ticket,
        $initialSnapshot,
        finalValidationCountTicketHistory($entityManager, $ticket),
        finalValidationCountAIAnalyses($aiAnalysisRepository, $ticket),
        'technician AI display',
    );
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();
    $entityManager->clear();
    $connection->rollBack();
}

echo "AI final validation tests: PASS\n";
