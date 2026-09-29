<?php

declare(strict_types=1);

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
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureTechnicianE2E(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicianE2EUser(
    string $email,
    array $roles,
    string $firstname,
    string $lastname,
    NativePasswordHasher $passwordHasher,
): User {
    $user = (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles($roles)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));

    $user->setPassword($passwordHasher->hash('CorrectHorseBatteryStaple123!'));

    return $user;
}

function technicianE2ETicket(
    User $client,
    string $title,
    string $description,
    DateTimeImmutable $createdAt,
    ?Category $category = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setCategory($category);
}

function technicianE2ERequest(string $path, string $method, Session $session, array $parameters = [], array $server = []): Request
{
    $request = Request::create($path, $method, $parameters, [], [], $server);
    $request->setSession($session);

    return $request;
}

function technicianE2EAuthenticatedRequest(
    TokenStorageInterface $tokenStorage,
    User $user,
    string $path,
    string $method,
    Session $session,
    array $parameters = [],
    array $server = [],
): Request {
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    return technicianE2ERequest($path, $method, $session, $parameters, $server);
}

function technicianE2ERender(RequestStack $requestStack, Request $request, callable $render): Response
{
    $requestStack->push($request);

    try {
        return $render();
    } finally {
        $requestStack->pop();
    }
}

function technicianE2ELoginCsrfToken(Kernel $kernel, Session $session): string
{
    $response = $kernel->handle(technicianE2ERequest('/login', 'GET', $session), HttpKernelInterface::MAIN_REQUEST);

    ensureTechnicianE2E(Response::HTTP_OK === $response->getStatusCode(), 'GET /login must return 200.');
    ensureTechnicianE2E(
        1 === preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $response->getContent(), $matches),
        'Login CSRF token must be rendered.',
    );

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function technicianE2ELogin(Kernel $kernel, User $user, Session $session, string $expectedPath): Response
{
    $response = $kernel->handle(technicianE2ERequest('/login', 'POST', $session, [
        '_username' => $user->getEmail(),
        '_password' => 'CorrectHorseBatteryStaple123!',
        '_csrf_token' => technicianE2ELoginCsrfToken($kernel, $session),
    ], [
        'HTTP_ORIGIN' => 'http://localhost',
    ]), HttpKernelInterface::MAIN_REQUEST);

    ensureTechnicianE2E(Response::HTTP_FOUND === $response->getStatusCode(), 'A valid technician login must redirect.');
    ensureTechnicianE2E($expectedPath === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), sprintf('A valid technician login must redirect to %s.', $expectedPath));
    ensureTechnicianE2E($session->has('_security_main'), 'A valid technician login must store an authenticated session.');

    return $response;
}

function technicianE2ECsrf(RequestStack $requestStack, Session $session, CsrfTokenManagerInterface $csrfTokenManager, string $id): string
{
    $request = technicianE2ERequest('/_csrf', 'GET', $session);
    $requestStack->push($request);

    try {
        return $csrfTokenManager->getToken($id)->getValue();
    } finally {
        $requestStack->pop();
    }
}

function technicianE2EHistoryRows(Connection $connection, Ticket $ticket): array
{
    return $connection->fetchAllAssociative(
        'SELECT action, old_value, new_value, changed_by_id FROM ticket_history WHERE ticket_id = ? ORDER BY id ASC',
        [$ticket->getId()],
    );
}

function technicianE2EEnsureHistory(
    Connection $connection,
    Ticket $ticket,
    string $action,
    ?string $oldValue,
    ?string $newValue,
    User $changedBy,
): void {
    $found = false;

    foreach (technicianE2EHistoryRows($connection, $ticket) as $row) {
        if (
            $action === $row['action']
            && $oldValue === $row['old_value']
            && $newValue === $row['new_value']
            && (string) $changedBy->getId() === (string) $row['changed_by_id']
        ) {
            $found = true;
            break;
        }
    }

    ensureTechnicianE2E($found, sprintf('Expected history entry was not found: %s.', $action));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var TechnicianController $controller */
$controller = $container->get(TechnicianController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var CsrfTokenManagerInterface $csrfTokenManager */
$csrfTokenManager = $controllerContainer->get('security.csrf.token_manager');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);
/** @var InterventionRepository $interventionRepository */
$interventionRepository = $entityManager->getRepository(Intervention::class);
/** @var TicketHistoryRepository $ticketHistoryRepository */
$ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
/** @var AIAnalysisRepository $aiAnalysisRepository */
$aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);
$ticketHistoryService = new TicketHistoryService($entityManager);
$passwordHasher = new NativePasswordHasher();
$connection->beginTransaction();

try {
    $client = technicianE2EUser('tech-e2e-client@example.test', ['ROLE_CLIENT'], 'Client', 'Support', $passwordHasher);
    $technicianA = technicianE2EUser('tech-e2e-a@example.test', ['ROLE_TECHNICIAN'], 'Alice', 'Tech', $passwordHasher);
    $technicianB = technicianE2EUser('tech-e2e-b@example.test', ['ROLE_TECHNICIAN'], 'Bob', 'Tech', $passwordHasher);
    $targetCategory = (new Category())->setName('Réseau');

    foreach ([$client, $technicianA, $technicianB, $targetCategory] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $mainTicket = technicianE2ETicket(
        $client,
        'Connexion VPN impossible E2E',
        'Le VPN refuse la connexion depuis le poste portable et affiche une erreur réseau.',
        new DateTimeImmutable('2099-02-01 10:00:00'),
    );
    $similarTicket = technicianE2ETicket(
        $client,
        'Connexion VPN lente similaire',
        'Ancien ticket sur une connexion VPN et une erreur réseau comparable.',
        new DateTimeImmutable('2099-01-31 10:00:00'),
        $targetCategory,
    );

    $entityManager->persist($mainTicket);
    $entityManager->persist($similarTicket);
    $entityManager->flush();

    $analysis = (new AIAnalysis())
        ->setTicket($mainTicket)
        ->setSummary('Résumé IA passif E2E')
        ->setSuggestedPriority(Ticket::PRIORITY_HIGH)
        ->setSuggestedCategory('Réseau')
        ->setKeywords(['VPN', 'réseau'])
        ->setSuggestions(['Vérifier la configuration VPN sans appliquer automatiquement.'])
        ->setCreatedAt(new DateTimeImmutable('2099-02-01 10:10:00'));
    $entityManager->persist($analysis);
    $entityManager->flush();

    $technicianASession = new Session(new MockArraySessionStorage());
    technicianE2ELogin($kernel, $technicianA, $technicianASession, '/technician');
    $technicianA = $entityManager->find(User::class, $technicianA->getId());
    ensureTechnicianE2E($technicianA instanceof User, 'Technician A must remain available after login.');

    $dashboardResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician', 'GET', $technicianASession),
        static fn (): Response => $controller->index($ticketRepository),
    );
    $dashboardHtml = (string) $dashboardResponse->getContent();
    ensureTechnicianE2E(Response::HTTP_OK === $dashboardResponse->getStatusCode(), 'GET /technician must return 200.');
    ensureTechnicianE2E(str_contains($dashboardHtml, 'Tickets à prendre'), 'Technician dashboard must render Tickets à prendre.');
    ensureTechnicianE2E(str_contains($dashboardHtml, 'Connexion VPN impossible E2E'), 'The OPEN unassigned ticket must appear on the technician dashboard.');

    $beforeAssignDetail = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId(), 'GET', $technicianASession),
        static fn (): Response => $controller->show($mainTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository),
    );
    $beforeAssignHtml = (string) $beforeAssignDetail->getContent();
    ensureTechnicianE2E(Response::HTTP_OK === $beforeAssignDetail->getStatusCode(), 'Technician A must read the unassigned ticket detail.');
    ensureTechnicianE2E(str_contains($beforeAssignHtml, 'Connexion VPN impossible E2E'), 'The ticket detail must display the ticket.');
    ensureTechnicianE2E(str_contains($beforeAssignHtml, "M'assigner ce ticket") || str_contains($beforeAssignHtml, 'M&#039;assigner ce ticket'), 'The assignment button must be visible before assignment.');
    ensureTechnicianE2E(!str_contains($beforeAssignHtml, 'Modifier le statut'), 'Status form must be hidden before assignment.');
    ensureTechnicianE2E(!str_contains($beforeAssignHtml, 'Modifier la priorité'), 'Priority form must be hidden before assignment.');
    ensureTechnicianE2E(!str_contains($beforeAssignHtml, 'Modifier la catégorie'), 'Category form must be hidden before assignment.');
    ensureTechnicianE2E(!str_contains($beforeAssignHtml, 'Ajouter une intervention'), 'Intervention form must be hidden before assignment.');

    $historyCountBeforeAssign = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]);
    $assignResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId().'/assign', 'POST', $technicianASession, [
            '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'assign-ticket-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']),
        static fn (): Response => $controller->assign((int) $mainTicket->getId(), technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/assign', 'POST', $technicianASession, [
            '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'assign-ticket-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']), $entityManager, $ticketHistoryService),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $assignResponse->getStatusCode(), 'Assigning the ticket must redirect.');
    $mainTicket = $ticketRepository->find($mainTicket->getId());
    ensureTechnicianE2E($mainTicket instanceof Ticket, 'The ticket must still exist after assignment.');
    ensureTechnicianE2E($mainTicket->getAssignedTo()?->getId() === $technicianA->getId(), 'The ticket must be assigned to Technician A.');
    ensureTechnicianE2E(Ticket::STATUS_OPEN === $mainTicket->getStatus(), 'Assignment must not change status.');
    ensureTechnicianE2E(Ticket::PRIORITY_MEDIUM === $mainTicket->getPriority(), 'Assignment must not change priority.');
    ensureTechnicianE2E(null === $mainTicket->getCategory(), 'Assignment must not change category.');
    ensureTechnicianE2E($historyCountBeforeAssign + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]), 'Assignment must create exactly one history entry.');
    technicianE2EEnsureHistory($connection, $mainTicket, 'TICKET_ASSIGNED', null, (string) $technicianA->getId(), $technicianA);

    $assignedListResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/assigned', 'GET', $technicianASession),
        static fn (): Response => $controller->assignedTickets($ticketRepository),
    );
    ensureTechnicianE2E(Response::HTTP_OK === $assignedListResponse->getStatusCode(), 'GET /technician/tickets/assigned must return 200.');
    ensureTechnicianE2E(str_contains((string) $assignedListResponse->getContent(), 'Connexion VPN impossible E2E'), 'Assigned ticket list must display the assigned ticket.');

    $statusRequest = technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId().'/status', 'POST', $technicianASession, [
        'status' => Ticket::STATUS_IN_PROGRESS,
        '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'update-ticket-status-'.$mainTicket->getId()),
    ], ['HTTP_ORIGIN' => 'http://localhost']);
    $statusResponse = technicianE2ERender(
        $requestStack,
        $statusRequest,
        static fn (): Response => $controller->updateStatus($mainTicket, $statusRequest, $ticketHistoryService),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $statusResponse->getStatusCode(), 'Status update must redirect.');
    $mainTicket = $ticketRepository->find($mainTicket->getId());
    ensureTechnicianE2E($mainTicket instanceof Ticket, 'The ticket must still exist after status update.');
    ensureTechnicianE2E(Ticket::STATUS_IN_PROGRESS === $mainTicket->getStatus(), 'Status must become IN_PROGRESS.');
    technicianE2EEnsureHistory($connection, $mainTicket, 'STATUS_CHANGED', Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, $technicianA);

    $priorityRequest = technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId().'/priority', 'POST', $technicianASession, [
        'priority' => Ticket::PRIORITY_HIGH,
        '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'update-ticket-priority-'.$mainTicket->getId()),
    ], ['HTTP_ORIGIN' => 'http://localhost']);
    $priorityResponse = technicianE2ERender(
        $requestStack,
        $priorityRequest,
        static fn (): Response => $controller->updatePriority($mainTicket, $priorityRequest, $ticketHistoryService),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $priorityResponse->getStatusCode(), 'Priority update must redirect.');
    $mainTicket = $ticketRepository->find($mainTicket->getId());
    ensureTechnicianE2E($mainTicket instanceof Ticket, 'The ticket must still exist after priority update.');
    ensureTechnicianE2E(Ticket::PRIORITY_HIGH === $mainTicket->getPriority(), 'Priority must become HIGH.');
    technicianE2EEnsureHistory($connection, $mainTicket, 'PRIORITY_CHANGED', Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, $technicianA);

    $categoryRequest = technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId().'/category', 'POST', $technicianASession, [
        'category_id' => (string) $targetCategory->getId(),
        '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'update-ticket-category-'.$mainTicket->getId()),
    ], ['HTTP_ORIGIN' => 'http://localhost']);
    $categoryResponse = technicianE2ERender(
        $requestStack,
        $categoryRequest,
        static fn (): Response => $controller->updateCategory($mainTicket, $categoryRequest, $categoryRepository, $ticketHistoryService),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $categoryResponse->getStatusCode(), 'Category update must redirect.');
    $entityManager->refresh($mainTicket);
    ensureTechnicianE2E($mainTicket->getCategory()?->getId() === $targetCategory->getId(), 'Category must become target category.');
    technicianE2EEnsureHistory($connection, $mainTicket, 'CATEGORY_CHANGED', null, (string) $targetCategory->getId(), $technicianA);

    $historyCountBeforeIntervention = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]);
    $interventionCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention WHERE ticket_id = ?', [$mainTicket->getId()]);
    $interventionContent = 'Intervention E2E : vérification VPN et configuration réseau.';
    $interventionRequest = technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId().'/interventions', 'POST', $technicianASession, [
        'intervention' => [
            'content' => $interventionContent,
            '_token' => technicianE2ECsrf($requestStack, $technicianASession, $csrfTokenManager, 'add-intervention-'.$mainTicket->getId()),
        ],
    ], ['HTTP_ORIGIN' => 'http://localhost']);
    $interventionResponse = technicianE2ERender(
        $requestStack,
        $interventionRequest,
        static fn (): Response => $controller->createIntervention($mainTicket, $interventionRequest, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository, $entityManager),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $interventionResponse->getStatusCode(), 'Intervention creation must redirect.');
    ensureTechnicianE2E($interventionCountBefore + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention WHERE ticket_id = ?', [$mainTicket->getId()]), 'Exactly one intervention must be created.');
    ensureTechnicianE2E($historyCountBeforeIntervention === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]), 'Intervention must not create history.');

    /** @var Intervention|null $intervention */
    $intervention = $interventionRepository->findOneBy(['ticket' => $mainTicket, 'content' => $interventionContent]);
    ensureTechnicianE2E($intervention instanceof Intervention, 'Created intervention must be found.');
    ensureTechnicianE2E($intervention->getTechnician()?->getId() === $technicianA->getId(), 'Intervention technician must be Technician A.');
    ensureTechnicianE2E($intervention->getCreatedAt() instanceof DateTimeImmutable, 'Intervention createdAt must be set server-side.');

    $finalDetailResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianA, '/technician/tickets/'.$mainTicket->getId(), 'GET', $technicianASession),
        static fn (): Response => $controller->show($mainTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository),
    );
    $finalHtml = (string) $finalDetailResponse->getContent();
    ensureTechnicianE2E(Response::HTTP_OK === $finalDetailResponse->getStatusCode(), 'Final technician detail must return 200.');
    foreach (['Ticket assigné', 'Statut modifié', 'Priorité modifiée', 'Catégorie modifiée'] as $label) {
        ensureTechnicianE2E(str_contains($finalHtml, $label), sprintf('Final history must display %s.', $label));
    }
    ensureTechnicianE2E(str_contains($finalHtml, $interventionContent), 'Final detail must display the intervention.');
    ensureTechnicianE2E(str_contains($finalHtml, 'Résumé IA passif E2E'), 'Final detail must display AI summary.');
    ensureTechnicianE2E(str_contains($finalHtml, 'HIGH'), 'Final detail must display AI suggested priority as passive data.');
    ensureTechnicianE2E(str_contains($finalHtml, 'Réseau'), 'Final detail must display AI suggested category/category data.');
    ensureTechnicianE2E(str_contains($finalHtml, 'VPN'), 'Final detail must display AI keywords.');
    ensureTechnicianE2E(str_contains($finalHtml, 'Vérifier la configuration VPN sans appliquer automatiquement.'), 'Final detail must display AI suggestions.');
    ensureTechnicianE2E(str_contains($finalHtml, 'Tickets similaires'), 'Final detail must display similar tickets section.');
    ensureTechnicianE2E(str_contains($finalHtml, 'Connexion VPN lente similaire'), 'Final detail must display the similar ticket.');
    ensureTechnicianE2E(!str_contains($finalHtml, '#'.$mainTicket->getId().' — Connexion VPN impossible E2E'), 'Current ticket must not appear as its own similar ticket.');
    ensureTechnicianE2E(Ticket::STATUS_IN_PROGRESS === $mainTicket->getStatus(), 'AI display must not change status.');
    ensureTechnicianE2E(Ticket::PRIORITY_HIGH === $mainTicket->getPriority(), 'AI display must not change priority.');
    ensureTechnicianE2E($mainTicket->getCategory()?->getId() === $targetCategory->getId(), 'AI display must not change category.');
    ensureTechnicianE2E($mainTicket->getAssignedTo()?->getId() === $technicianA->getId(), 'AI display must not change assignment.');

    technicianE2EEnsureHistory($connection, $mainTicket, 'TICKET_ASSIGNED', null, (string) $technicianA->getId(), $technicianA);
    technicianE2EEnsureHistory($connection, $mainTicket, 'STATUS_CHANGED', Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, $technicianA);
    technicianE2EEnsureHistory($connection, $mainTicket, 'PRIORITY_CHANGED', Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, $technicianA);
    technicianE2EEnsureHistory($connection, $mainTicket, 'CATEGORY_CHANGED', null, (string) $targetCategory->getId(), $technicianA);

    $technicianBSession = new Session(new MockArraySessionStorage());
    technicianE2ELogin($kernel, $technicianB, $technicianBSession, '/technician');
    $technicianB = $entityManager->find(User::class, $technicianB->getId());
    ensureTechnicianE2E($technicianB instanceof User, 'Technician B must remain available after login.');

    $technicianBDetailResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianB, '/technician/tickets/'.$mainTicket->getId(), 'GET', $technicianBSession),
        static fn (): Response => $controller->show($mainTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository),
    );
    $technicianBHtml = (string) $technicianBDetailResponse->getContent();
    ensureTechnicianE2E(Response::HTTP_OK === $technicianBDetailResponse->getStatusCode(), 'Technician B must be able to read the ticket detail.');
    ensureTechnicianE2E(str_contains($technicianBHtml, 'Résumé IA passif E2E'), 'Technician B must see AI analysis in read-only mode.');
    ensureTechnicianE2E(str_contains($technicianBHtml, 'Ticket assigné'), 'Technician B must see history in read-only mode.');
    ensureTechnicianE2E(str_contains($technicianBHtml, $interventionContent), 'Technician B must see interventions in read-only mode.');
    ensureTechnicianE2E(str_contains($technicianBHtml, 'Connexion VPN lente similaire'), 'Technician B must see similar tickets.');
    ensureTechnicianE2E(!str_contains($technicianBHtml, 'Modifier le statut'), 'Technician B must not see status mutation form.');
    ensureTechnicianE2E(!str_contains($technicianBHtml, 'Modifier la priorité'), 'Technician B must not see priority mutation form.');
    ensureTechnicianE2E(!str_contains($technicianBHtml, 'Modifier la catégorie'), 'Technician B must not see category mutation form.');
    ensureTechnicianE2E(!str_contains($technicianBHtml, 'Ajouter une intervention'), 'Technician B must not see intervention form.');

    $ticketSnapshot = [
        'status' => $mainTicket->getStatus(),
        'priority' => $mainTicket->getPriority(),
        'category' => $mainTicket->getCategory()?->getId(),
        'assignedTo' => $mainTicket->getAssignedTo()?->getId(),
        'interventions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention WHERE ticket_id = ?', [$mainTicket->getId()]),
        'histories' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]),
    ];

    foreach ([
        'status' => static fn () => $controller->updateStatus($mainTicket, technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/status', 'POST', $technicianBSession, [
            'status' => Ticket::STATUS_CLOSED,
            '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'update-ticket-status-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']), $ticketHistoryService),
        'priority' => static fn () => $controller->updatePriority($mainTicket, technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/priority', 'POST', $technicianBSession, [
            'priority' => Ticket::PRIORITY_URGENT,
            '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'update-ticket-priority-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']), $ticketHistoryService),
        'category' => static fn () => $controller->updateCategory($mainTicket, technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/category', 'POST', $technicianBSession, [
            'category_id' => (string) $targetCategory->getId(),
            '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'update-ticket-category-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']), $categoryRepository, $ticketHistoryService),
        'intervention' => static fn () => $controller->createIntervention($mainTicket, technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/interventions', 'POST', $technicianBSession, [
            'intervention' => [
                'content' => 'Intervention interdite par technicianB',
                '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'add-intervention-'.$mainTicket->getId()),
            ],
        ], ['HTTP_ORIGIN' => 'http://localhost']), $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository, $entityManager),
    ] as $label => $attempt) {
        $tokenStorage->setToken(new UsernamePasswordToken($technicianB, 'main', $technicianB->getRoles()));

        try {
            $attempt();
            ensureTechnicianE2E(false, sprintf('Technician B %s POST must be forbidden.', $label));
        } catch (AccessDeniedException) {
            // Expected: a non-assigned technician cannot mutate the ticket.
        }
    }

    $assignByBResponse = technicianE2ERender(
        $requestStack,
        technicianE2EAuthenticatedRequest($tokenStorage, $technicianB, '/technician/tickets/'.$mainTicket->getId().'/assign', 'POST', $technicianBSession, [
            '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'assign-ticket-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']),
        static fn (): Response => $controller->assign((int) $mainTicket->getId(), technicianE2ERequest('/technician/tickets/'.$mainTicket->getId().'/assign', 'POST', $technicianBSession, [
            '_token' => technicianE2ECsrf($requestStack, $technicianBSession, $csrfTokenManager, 'assign-ticket-'.$mainTicket->getId()),
        ], ['HTTP_ORIGIN' => 'http://localhost']), $entityManager, $ticketHistoryService),
    );
    ensureTechnicianE2E(Response::HTTP_FOUND === $assignByBResponse->getStatusCode(), 'Technician B assign attempt on an already assigned ticket must redirect without overwrite.');

    $mainTicket = $ticketRepository->find($mainTicket->getId());
    ensureTechnicianE2E($mainTicket instanceof Ticket, 'The ticket must still exist after Technician B assign attempt.');
    ensureTechnicianE2E($ticketSnapshot['status'] === $mainTicket->getStatus(), 'Technician B must not change status.');
    ensureTechnicianE2E($ticketSnapshot['priority'] === $mainTicket->getPriority(), 'Technician B must not change priority.');
    ensureTechnicianE2E($ticketSnapshot['category'] === $mainTicket->getCategory()?->getId(), 'Technician B must not change category.');
    ensureTechnicianE2E($ticketSnapshot['assignedTo'] === $mainTicket->getAssignedTo()?->getId(), 'Technician B must not overwrite assignment.');
    ensureTechnicianE2E($ticketSnapshot['interventions'] === (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention WHERE ticket_id = ?', [$mainTicket->getId()]), 'Technician B must not create interventions.');
    ensureTechnicianE2E($ticketSnapshot['histories'] === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$mainTicket->getId()]), 'Technician B forbidden attempts must not create history.');

    $tokenStorage->setToken(new UsernamePasswordToken($technicianA, 'main', $technicianA->getRoles()));
    $logoutResponse = $kernel->handle(technicianE2ERequest('/logout', 'GET', $technicianASession), HttpKernelInterface::MAIN_REQUEST);
    ensureTechnicianE2E(Response::HTTP_FOUND === $logoutResponse->getStatusCode(), 'Technician logout must redirect.');
    ensureTechnicianE2E('/login' === parse_url((string) $logoutResponse->headers->get('Location'), PHP_URL_PATH), 'Technician logout must redirect to /login.');
    ensureTechnicianE2E(!$technicianASession->has('_security_main'), 'Technician logout must clear the authenticated session.');

    $protectedAfterLogoutResponse = $kernel->handle(technicianE2ERequest('/technician', 'GET', $technicianASession), HttpKernelInterface::MAIN_REQUEST);
    ensureTechnicianE2E(Response::HTTP_FOUND === $protectedAfterLogoutResponse->getStatusCode(), 'Technician protected access after logout must redirect.');
    ensureTechnicianE2E('/login' === parse_url((string) $protectedAfterLogoutResponse->headers->get('Location'), PHP_URL_PATH), 'Technician protected access after logout must redirect to /login.');

    echo "Technician E2E flow tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
