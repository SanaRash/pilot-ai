<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\Controller\ClientController;
use App\Controller\ClientTicketController;
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
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class ClientE2EAIProvider implements AIProviderInterface
{
    public int $callCount = 0;

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        ++$this->callCount;

        return new AIAnalysisResult(
            summary: 'Le problème concerne une demande client.',
            suggestedPriority: Ticket::PRIORITY_MEDIUM,
            suggestedCategory: 'Support',
            keywords: ['support'],
            suggestions: ['Examiner la demande.'],
        );
    }
}

function ensureClientE2E(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientE2EUser(
    string $email,
    string $firstname,
    string $lastname,
    NativePasswordHasher $passwordHasher,
): User
{
    $user = (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles(['ROLE_CLIENT'])
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));

    $user->setPassword($passwordHasher->hash('CorrectHorseBatteryStaple123!'));

    return $user;
}

function clientE2ERequest(string $path, string $method, Session $session, array $parameters = [], array $server = []): Request
{
    $request = Request::create($path, $method, $parameters, [], [], $server);
    $request->setSession($session);

    return $request;
}


function clientE2EAuthenticatedRequest(
    TokenStorageInterface $tokenStorage,
    User $user,
    string $path,
    string $method,
    Session $session,
    array $parameters = [],
    array $server = [],
): Request {
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    return clientE2ERequest($path, $method, $session, $parameters, $server);
}

function clientE2ELoginCsrfToken(Kernel $kernel, Session $session): string
{
    $response = $kernel->handle(clientE2ERequest('/login', 'GET', $session), HttpKernelInterface::MAIN_REQUEST);

    ensureClientE2E(Response::HTTP_OK === $response->getStatusCode(), 'GET /login must return 200.');
    ensureClientE2E(
        1 === preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $response->getContent(), $matches),
        'Login CSRF token must be rendered.',
    );

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clientE2ELogin(Kernel $kernel, User $user, Session $session): Response
{
    $response = $kernel->handle(clientE2ERequest('/login', 'POST', $session, [
        '_username' => $user->getEmail(),
        '_password' => 'CorrectHorseBatteryStaple123!',
        '_csrf_token' => clientE2ELoginCsrfToken($kernel, $session),
    ], [
        'HTTP_ORIGIN' => 'http://localhost',
    ]), HttpKernelInterface::MAIN_REQUEST);

    ensureClientE2E(Response::HTTP_FOUND === $response->getStatusCode(), 'A valid client login must redirect.');
    ensureClientE2E('/client' === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), 'A valid client login must redirect to /client.');
    ensureClientE2E($session->has('_security_main'), 'A valid client login must store an authenticated session.');

    return $response;
}


function clientE2ERender(RequestStack $requestStack, Request $request, callable $render): Response
{
    $requestStack->push($request);

    try {
        return $render();
    } finally {
        $requestStack->pop();
    }
}

function clientE2ETicketCsrfToken(string $html): string
{
    ensureClientE2E(
        1 === preg_match('/<input[^>]+name="ticket\[_token\]"[^>]*>/', $html, $inputMatches),
        'Ticket form CSRF input must be rendered.',
    );
    ensureClientE2E(
        1 === preg_match('/value="([^"]+)"/', $inputMatches[0], $matches),
        'Ticket form CSRF token must be rendered.',
    );

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

function clientE2ECsrfToken(
    RequestStack $requestStack,
    Session $session,
    CsrfTokenManagerInterface $csrfTokenManager,
    string $tokenId,
): string {
    $request = clientE2ERequest('/_csrf', 'GET', $session);
    $requestStack->push($request);

    try {
        return $csrfTokenManager->getToken($tokenId)->getValue();
    } finally {
        $requestStack->pop();
    }
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var ClientController $clientController */
$clientController = $container->get(ClientController::class);
$containerTicketController = $container->get(ClientTicketController::class);
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($containerTicketController);
$aiProvider = new ClientE2EAIProvider();
$clientTicketController = new ClientTicketController(new AIService($aiProvider, $entityManager), new NullLogger());
$clientTicketController->setContainer($controllerContainer);
$passwordHasher = new NativePasswordHasher();
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var InterventionRepository $interventionRepository */
$interventionRepository = $entityManager->getRepository(Intervention::class);
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);
/** @var TicketHistoryRepository $ticketHistoryRepository */
$ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
/** @var AIAnalysisRepository $aiAnalysisRepository */
$aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);
$technicianController = $kernel->getContainer()->get(TechnicianController::class);
/** @var CsrfTokenManagerInterface $csrfTokenManager */
$csrfTokenManager = $controllerContainer->get('security.csrf.token_manager');
$ticketHistoryService = new TicketHistoryService($entityManager);
$connection->beginTransaction();

try {
    $clientA = clientE2EUser('client-e2e-a@example.test', 'Client', 'Alpha', $passwordHasher);
    $clientB = clientE2EUser('client-e2e-b@example.test', 'Client', 'Beta', $passwordHasher);
    $technician = clientE2EUser('client-e2e-technician@example.test', 'Technicien', 'E2E', $passwordHasher)
        ->setRoles(['ROLE_TECHNICIAN']);

    $entityManager->persist($clientA);
    $entityManager->persist($clientB);
    $entityManager->persist($technician);
    $entityManager->flush();
    $clientAId = $clientA->getId();
    $clientBId = $clientB->getId();

    $clientASession = new Session(new MockArraySessionStorage());
    clientE2ELogin($kernel, $clientA, $clientASession);
    $clientA = $entityManager->find(User::class, $clientAId);
    ensureClientE2E($clientA instanceof User, 'Client A must remain available after login.');

    $dashboardResponse = clientE2ERender(
        $requestStack,
        clientE2EAuthenticatedRequest($tokenStorage, $clientA, '/client', 'GET', $clientASession),
        static fn (): Response => $clientController->index($ticketRepository),
    );
    ensureClientE2E(Response::HTTP_OK === $dashboardResponse->getStatusCode(), 'Client A dashboard must return 200.');
    ensureClientE2E(str_contains((string) $dashboardResponse->getContent(), 'Demandes récentes'), 'Client A dashboard must render recent requests.');

    $newTicketResponse = clientE2ERender(
        $requestStack,
        clientE2EAuthenticatedRequest($tokenStorage, $clientA, '/client/ticket/new', 'GET', $clientASession),
        static fn (): Response => $clientTicketController->new(clientE2ERequest('/client/ticket/new', 'GET', $clientASession), $entityManager, $ticketHistoryService),
    );
    ensureClientE2E(Response::HTTP_OK === $newTicketResponse->getStatusCode(), 'GET /client/ticket/new must return 200 for Client A.');
    $newTicketHtml = (string) $newTicketResponse->getContent();
    $ticketCsrfToken = clientE2ETicketCsrfToken($newTicketHtml);

    $title = 'Ticket E2E client '.bin2hex(random_bytes(6));
    $description = 'Description E2E du ticket client créée depuis le formulaire applicatif.';
    $ticketCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');

    $createRequest = clientE2EAuthenticatedRequest($tokenStorage, $clientA, '/client/ticket/new', 'POST', $clientASession, [
        'ticket' => [
            'title' => $title,
            'description' => $description,
            '_token' => $ticketCsrfToken,
        ],
    ], [
        'HTTP_ORIGIN' => 'http://localhost',
    ]);
    $createResponse = clientE2ERender(
        $requestStack,
        $createRequest,
        static fn (): Response => $clientTicketController->new($createRequest, $entityManager, $ticketHistoryService),
    );

    ensureClientE2E(Response::HTTP_FOUND === $createResponse->getStatusCode(), 'Valid ticket creation must redirect.');
    ensureClientE2E('/client/tickets' === parse_url((string) $createResponse->headers->get('Location'), PHP_URL_PATH), 'Valid ticket creation must redirect to /client/tickets.');
    ensureClientE2E($ticketCountBefore + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Valid ticket creation must create exactly one ticket.');
    ensureClientE2E($historyCountBefore + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Valid ticket creation must create exactly one history entry.');
    ensureClientE2E(1 === $aiProvider->callCount, 'Client ticket creation must invoke the fake AI provider exactly once.');

    $ticketListAfterCreateResponse = clientE2ERender(
        $requestStack,
        clientE2EAuthenticatedRequest($tokenStorage, $clientA, '/client/tickets', 'GET', $clientASession),
        static fn (): Response => $clientTicketController->tickets($ticketRepository),
    );
    $ticketListAfterCreateHtml = (string) $ticketListAfterCreateResponse->getContent();
    ensureClientE2E(Response::HTTP_OK === $ticketListAfterCreateResponse->getStatusCode(), 'Client A ticket list after creation must return 200.');
    ensureClientE2E(str_contains($ticketListAfterCreateHtml, 'Votre demande a bien été envoyée.'), 'The success flash must be visible on the redirected ticket list.');

    /** @var Ticket|null $createdTicket */
    $createdTicket = $entityManager->getRepository(Ticket::class)->findOneBy([
        'title' => $title,
        'createdBy' => $clientA,
    ]);
    ensureClientE2E($createdTicket instanceof Ticket, 'The created ticket must be found deterministically by title and owner.');
    ensureClientE2E($description === $createdTicket->getDescription(), 'The created ticket description must match the submitted value.');
    ensureClientE2E($createdTicket->getCreatedBy() === $clientA, 'The created ticket owner must be Client A.');
    ensureClientE2E(Ticket::STATUS_OPEN === $createdTicket->getStatus(), 'The created ticket status must be OPEN.');
    ensureClientE2E(Ticket::PRIORITY_MEDIUM === $createdTicket->getPriority(), 'The created ticket priority must be MEDIUM.');
    ensureClientE2E('APP' === $createdTicket->getSource(), 'The created ticket source must be APP.');
    ensureClientE2E($createdTicket->getCreatedAt() instanceof DateTimeImmutable, 'The created ticket must have a server-side createdAt.');
    ensureClientE2E(null === $createdTicket->getUpdatedAt(), 'The created ticket updatedAt must be null.');
    ensureClientE2E(null === $createdTicket->getCategory(), 'The created ticket category must be null.');
    ensureClientE2E(null === $createdTicket->getAssignedTo(), 'The created ticket assignedTo must be null.');
    $createdAnalyses = $entityManager->getRepository(AIAnalysis::class)->findBy(['ticket' => $createdTicket]);
    ensureClientE2E(1 === count($createdAnalyses), 'The client-created ticket must have one persisted AI analysis.');

    $historyAction = $connection->fetchOne(
        'SELECT action FROM ticket_history WHERE ticket_id = ? ORDER BY id DESC LIMIT 1',
        [$createdTicket->getId()],
    );
    ensureClientE2E('TICKET_CREATED' === $historyAction, 'A minimal TICKET_CREATED history entry must exist for the created ticket.');

    $technician = $entityManager->find(User::class, $technician->getId());
    ensureClientE2E($technician instanceof User, 'The E2E technician must remain available.');
    $technicianSession = new Session(new MockArraySessionStorage());
    $assignRequest = clientE2EAuthenticatedRequest(
        $tokenStorage,
        $technician,
        '/technician/tickets/'.$createdTicket->getId().'/assign',
        'POST',
        $technicianSession,
        ['_token' => clientE2ECsrfToken($requestStack, $technicianSession, $csrfTokenManager, 'assign-ticket-'.$createdTicket->getId())],
        ['HTTP_ORIGIN' => 'http://localhost'],
    );
    $assignResponse = clientE2ERender(
        $requestStack,
        $assignRequest,
        static fn (): Response => $technicianController->assign((int) $createdTicket->getId(), $assignRequest, $entityManager, $ticketHistoryService),
    );
    ensureClientE2E(Response::HTTP_FOUND === $assignResponse->getStatusCode(), 'Technician assignment must redirect.');
    $entityManager->refresh($createdTicket);
    ensureClientE2E($technician->getId() === $createdTicket->getAssignedTo()?->getId(), 'The technician must be assigned before publishing an update.');

    $visibleInterventionContent = 'Mise à jour publiée au client.';
    $interventionRequest = clientE2EAuthenticatedRequest(
        $tokenStorage,
        $technician,
        '/technician/tickets/'.$createdTicket->getId().'/interventions',
        'POST',
        $technicianSession,
        ['intervention' => [
            'content' => $visibleInterventionContent,
            'isClientVisible' => '1',
            '_token' => clientE2ECsrfToken($requestStack, $technicianSession, $csrfTokenManager, 'add-intervention-'.$createdTicket->getId()),
        ]],
        ['HTTP_ORIGIN' => 'http://localhost'],
    );
    $interventionResponse = clientE2ERender(
        $requestStack,
        $interventionRequest,
        static fn (): Response => $technicianController->createIntervention(
            $createdTicket,
            $interventionRequest,
            $categoryRepository,
            $interventionRepository,
            $ticketHistoryRepository,
            $aiAnalysisRepository,
            $entityManager,
        ),
    );
    ensureClientE2E(Response::HTTP_FOUND === $interventionResponse->getStatusCode(), 'Technician intervention creation must redirect.');

    $internalInterventionContent = 'Note non publiée.';
    $internalRequest = clientE2EAuthenticatedRequest(
        $tokenStorage,
        $technician,
        '/technician/tickets/'.$createdTicket->getId().'/interventions',
        'POST',
        $technicianSession,
        ['intervention' => [
            'content' => $internalInterventionContent,
            '_token' => clientE2ECsrfToken($requestStack, $technicianSession, $csrfTokenManager, 'add-intervention-'.$createdTicket->getId()),
        ]],
        ['HTTP_ORIGIN' => 'http://localhost'],
    );
    $internalResponse = clientE2ERender(
        $requestStack,
        $internalRequest,
        static fn (): Response => $technicianController->createIntervention(
            $createdTicket,
            $internalRequest,
            $categoryRepository,
            $interventionRepository,
            $ticketHistoryRepository,
            $aiAnalysisRepository,
            $entityManager,
        ),
    );
    ensureClientE2E(Response::HTTP_FOUND === $internalResponse->getStatusCode(), 'Technician internal intervention creation must redirect.');

    ensureClientE2E(str_contains($ticketListAfterCreateHtml, $title), 'Client A ticket list must display the created ticket title.');
    ensureClientE2E(str_contains($ticketListAfterCreateHtml, '/client/tickets/'.$createdTicket->getId()), 'Client A ticket list must link to the created ticket detail.');

    $ticketDetailResponse = clientE2ERender(
        $requestStack,
        clientE2EAuthenticatedRequest($tokenStorage, $clientA, '/client/tickets/'.$createdTicket->getId(), 'GET', $clientASession),
        static fn (): Response => $clientTicketController->show((int) $createdTicket->getId(), $ticketRepository, $interventionRepository),
    );
    $ticketDetailHtml = (string) $ticketDetailResponse->getContent();
    ensureClientE2E(Response::HTTP_OK === $ticketDetailResponse->getStatusCode(), 'Client A ticket detail must return 200.');
    ensureClientE2E(str_contains($ticketDetailHtml, $title), 'Client A ticket detail must display the title.');
    ensureClientE2E(str_contains($ticketDetailHtml, $description), 'Client A ticket detail must display the description.');
    ensureClientE2E(str_contains($ticketDetailHtml, 'Ouvert'), 'Client A ticket detail must display the translated status.');
    ensureClientE2E(str_contains($ticketDetailHtml, 'Demande créée'), 'Client A ticket detail must display the creation follow-up line.');
    ensureClientE2E(str_contains($ticketDetailHtml, $visibleInterventionContent), 'The client must see the technician-published update.');
    ensureClientE2E(!str_contains($ticketDetailHtml, $internalInterventionContent), 'The client must not see internal technician notes.');

    foreach ([
        'Moyenne',
        'Non catégorisé',
        'Source',
        'Assigné',
        'Interventions',
        'Historique',
        'Analyses IA',
        'TicketHistory',
    ] as $forbiddenText) {
        ensureClientE2E(!str_contains($ticketDetailHtml, $forbiddenText), sprintf('Client ticket detail must not expose internal data: %s', $forbiddenText));
    }

    $clientBSession = new Session(new MockArraySessionStorage());
    clientE2ELogin($kernel, $clientB, $clientBSession);
    $clientB = $entityManager->find(User::class, $clientBId);
    ensureClientE2E($clientB instanceof User, 'Client B must remain available after login.');

    $clientBListResponse = clientE2ERender(
        $requestStack,
        clientE2EAuthenticatedRequest($tokenStorage, $clientB, '/client/tickets', 'GET', $clientBSession),
        static fn (): Response => $clientTicketController->tickets($ticketRepository),
    );
    $clientBListHtml = (string) $clientBListResponse->getContent();
    ensureClientE2E(Response::HTTP_OK === $clientBListResponse->getStatusCode(), 'Client B ticket list must return 200.');
    ensureClientE2E(!str_contains($clientBListHtml, $title), 'Client B ticket list must not display Client A ticket title.');
    ensureClientE2E(!str_contains($clientBListHtml, '/client/tickets/'.$createdTicket->getId()), 'Client B ticket list must not link to Client A ticket detail.');

    try {
        clientE2ERender(
            $requestStack,
            clientE2EAuthenticatedRequest($tokenStorage, $clientB, '/client/tickets/'.$createdTicket->getId(), 'GET', $clientBSession),
            static fn (): Response => $clientTicketController->show((int) $createdTicket->getId(), $ticketRepository, $interventionRepository),
        );

        throw new RuntimeException('Client B reached Client A ticket detail.');
    } catch (NotFoundHttpException) {
        // Expected: client ticket detail is isolated by id + createdBy.
    }

    $tokenStorage->setToken(new UsernamePasswordToken($clientA, 'main', $clientA->getRoles()));
    $logoutResponse = $kernel->handle(clientE2ERequest('/logout', 'GET', $clientASession), HttpKernelInterface::MAIN_REQUEST);
    ensureClientE2E(Response::HTTP_FOUND === $logoutResponse->getStatusCode(), 'Client A logout must redirect.');
    ensureClientE2E('/login' === parse_url((string) $logoutResponse->headers->get('Location'), PHP_URL_PATH), 'Client A logout must redirect to /login.');
    ensureClientE2E(!$clientASession->has('_security_main'), 'Client A logout must remove the authenticated session.');

    $protectedAfterLogoutResponse = $kernel->handle(clientE2ERequest('/client', 'GET', $clientASession), HttpKernelInterface::MAIN_REQUEST);
    ensureClientE2E(Response::HTTP_FOUND === $protectedAfterLogoutResponse->getStatusCode(), 'Client A protected access after logout must redirect.');
    ensureClientE2E('/login' === parse_url((string) $protectedAfterLogoutResponse->headers->get('Location'), PHP_URL_PATH), 'Client A protected access after logout must redirect to /login.');

    echo "Client E2E flow tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
