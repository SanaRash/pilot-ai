<?php

declare(strict_types=1);

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
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureSecurityMatrix(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function securityMatrixUser(
    string $email,
    array $roles,
    string $firstname = 'Security',
    string $lastname = 'Matrix',
): User {
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles($roles)
        ->setPassword(password_hash('CorrectHorseBatteryStaple123!', PASSWORD_DEFAULT))
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function securityMatrixTicket(
    string $title,
    User $createdBy,
    ?User $assignedTo = null,
    string $status = Ticket::STATUS_OPEN,
    string $priority = Ticket::PRIORITY_MEDIUM,
    ?Category $category = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description du ticket de test sécurité.')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2026-01-02 10:00:00'))
        ->setUpdatedAt(null)
        ->setCreatedBy($createdBy)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function securityMatrixControllerContainer(AbstractController $controller): ContainerInterface
{
    $property = new ReflectionProperty(AbstractController::class, 'container');
    /** @var ContainerInterface $controllerContainer */
    $controllerContainer = $property->getValue($controller);

    return $controllerContainer;
}

function securityMatrixSetUser(TokenStorageInterface $tokenStorage, ?User $user): void
{
    $tokenStorage->setToken(null === $user ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()));
}

function securityMatrixHandle(
    Kernel $kernel,
    ?Session $session,
    string $path,
    string $method = 'GET',
): Response {
    $request = Request::create($path, $method);

    if (null !== $session) {
        $request->setSession($session);
    }

    return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
}

function assertSecurityMatrixStatus(
    Kernel $kernel,
    ?Session $session,
    string $path,
    int $expectedStatus,
    string $label,
): void {
    $response = securityMatrixHandle($kernel, $session, $path);
    ensureSecurityMatrix($expectedStatus === $response->getStatusCode(), sprintf(
        '%s must return HTTP %d, got HTTP %d.',
        $label,
        $expectedStatus,
        $response->getStatusCode(),
    ));

    if (Response::HTTP_FOUND === $expectedStatus) {
        ensureSecurityMatrix('/login' === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), sprintf(
            '%s must redirect to /login.',
            $label,
        ));
    }
}

function securityMatrixLoginCsrfToken(Kernel $kernel, Session $session): string
{
    $request = Request::create('/login', 'GET');
    $request->setSession($session);
    $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);

    ensureSecurityMatrix(Response::HTTP_OK === $response->getStatusCode(), 'GET /login must render before submitting credentials.');
    preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $response->getContent(), $matches);
    ensureSecurityMatrix(isset($matches[1]) && '' !== $matches[1], 'Login CSRF token must be rendered.');

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assertSecurityMatrixLoginRedirectsTo(Kernel $kernel, User $user, string $expectedPath): Session
{
    $session = new Session(new MockArraySessionStorage());
    $csrfToken = securityMatrixLoginCsrfToken($kernel, $session);
    $request = Request::create('/login', 'POST', [
        '_username' => $user->getEmail(),
        '_password' => 'CorrectHorseBatteryStaple123!',
        '_csrf_token' => $csrfToken,
    ], [], [], ['HTTP_ORIGIN' => 'http://localhost']);
    $request->setSession($session);

    $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);

    ensureSecurityMatrix(Response::HTTP_FOUND === $response->getStatusCode(), sprintf('Valid login for %s must redirect.', $user->getEmail()));
    ensureSecurityMatrix($expectedPath === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), sprintf(
        'Valid login for %s must redirect to %s, got %s.',
        $user->getEmail(),
        $expectedPath,
        (string) $response->headers->get('Location'),
    ));
    ensureSecurityMatrix($session->has('_security_main'), sprintf('Valid login for %s must store the security token in session.', $user->getEmail()));

    return $session;
}

function assertSecurityMatrixThrowsAccessDenied(callable $callable, string $label): void
{
    try {
        $callable();
    } catch (AccessDeniedHttpException|AccessDeniedException) {
        return;
    }

    throw new RuntimeException(sprintf('%s must throw an access denied exception.', $label));
}


function assertSecurityMatrixGranted(
    TokenStorageInterface $tokenStorage,
    AuthorizationCheckerInterface $authorizationChecker,
    ?User $user,
    string $role,
    bool $expected,
    string $label,
): void {
    securityMatrixSetUser($tokenStorage, $user);
    ensureSecurityMatrix($expected === $authorizationChecker->isGranted($role), sprintf(
        '%s must %s %s.',
        $label,
        $expected ? 'be granted' : 'not be granted',
        $role,
    ));
}


function assertSecurityMatrixThrowsAccessDeniedWithRequest(
    mixed $requestStack,
    Request $request,
    callable $callable,
    string $label,
): void {
    $request->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($request);

    try {
        assertSecurityMatrixThrowsAccessDenied($callable, $label);
    } finally {
        $requestStack->pop();
    }
}

function securityMatrixEmailResponse(
    Kernel $kernel,
    ?string $authorization,
    string $body = '{"sender":"client@example.test","subject":"Sujet","content":"Contenu"}',
    ?string $contentType = 'application/json',
): Response {
    $server = ['HTTP_ACCEPT' => 'application/json'];

    if (null !== $authorization) {
        $server['HTTP_AUTHORIZATION'] = $authorization;
    }

    if (null !== $contentType) {
        $server['CONTENT_TYPE'] = $contentType;
    }

    return $kernel->handle(Request::create('/api/tickets/email', 'POST', server: $server, content: $body), HttpKernelInterface::MAIN_REQUEST);
}

function assertSecurityMatrixNoMutation(EntityManagerInterface $entityManager, string $label, int $tickets, int $interventions, int $histories): void
{
    $entityManager->clear();
    ensureSecurityMatrix($tickets === (int) $entityManager->createQuery('SELECT COUNT(t.id) FROM App\Entity\Ticket t')->getSingleScalarResult(), $label.' must not change ticket count.');
    ensureSecurityMatrix($interventions === (int) $entityManager->createQuery('SELECT COUNT(i.id) FROM App\Entity\Intervention i')->getSingleScalarResult(), $label.' must not change intervention count.');
    ensureSecurityMatrix($histories === (int) $entityManager->createQuery('SELECT COUNT(h.id) FROM App\Entity\TicketHistory h')->getSingleScalarResult(), $label.' must not change history count.');
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_ENV['PILOTAI_EMAIL_WEBHOOK_SECRET'] = 'security_matrix_secret_0123456789abcdef';
$_SERVER['PILOTAI_EMAIL_WEBHOOK_SECRET'] = 'security_matrix_secret_0123456789abcdef';
putenv('PILOTAI_EMAIL_WEBHOOK_SECRET=security_matrix_secret_0123456789abcdef');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$connection->beginTransaction();

try {
    $securityConfig = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
    ensureSecurityMatrix([
        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/technician', 'roles' => 'ROLE_TECHNICIAN'],
        ['path' => '^/client', 'roles' => 'ROLE_CLIENT'],
    ] === ($securityConfig['security']['access_control'] ?? null), 'The access_control role rules or order changed.');

    /** @var ClientController $clientController */
    $clientController = $container->get(ClientController::class);
    $controllerContainer = securityMatrixControllerContainer($clientController);
    /** @var TokenStorageInterface $tokenStorage */
    $tokenStorage = $controllerContainer->get('security.token_storage');
    /** @var AuthorizationCheckerInterface $authorizationChecker */
    $authorizationChecker = $controllerContainer->get('security.authorization_checker');
    $requestStack = $container->get('request_stack');

    $clientUser = securityMatrixUser('security-client@example.test', ['ROLE_CLIENT'], 'Client', 'Matrix');
    $technicianUser = securityMatrixUser('security-technician@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Matrix');
    $adminUser = securityMatrixUser('security-admin@example.test', ['ROLE_ADMIN'], 'Admin', 'Matrix');
    $technicianClientUser = securityMatrixUser('security-tech-client@example.test', ['ROLE_TECHNICIAN', 'ROLE_CLIENT'], 'TechClient', 'Matrix');
    $adminTechnicianClientUser = securityMatrixUser('security-admin-tech-client@example.test', ['ROLE_ADMIN', 'ROLE_TECHNICIAN', 'ROLE_CLIENT'], 'AdminTechClient', 'Matrix');
    $otherTechnicianUser = securityMatrixUser('security-other-technician@example.test', ['ROLE_TECHNICIAN'], 'Other', 'Tech');

    $category = (new Category())->setName('Sécurité');

    foreach ([$clientUser, $technicianUser, $adminUser, $technicianClientUser, $adminTechnicianClientUser, $otherTechnicianUser, $category] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $clientTicket = securityMatrixTicket('Ticket client sécurité', $clientUser);
    $assignedTicket = securityMatrixTicket('Ticket assigné sécurité', $clientUser, $technicianUser, Ticket::STATUS_OPEN, Ticket::PRIORITY_MEDIUM, $category);
    $freeTicket = securityMatrixTicket('Ticket libre sécurité', $clientUser);
    $otherAssignedTicket = securityMatrixTicket('Ticket autre technicien sécurité', $clientUser, $otherTechnicianUser);

    foreach ([$clientTicket, $assignedTicket, $freeTicket, $otherAssignedTicket] as $ticket) {
        $entityManager->persist($ticket);
    }
    $entityManager->flush();

    foreach (['/client', '/client/tickets', '/client/ticket/new'] as $path) {
        assertSecurityMatrixStatus($kernel, null, $path, Response::HTTP_FOUND, 'anonymous '.$path);
    }
    foreach (['/technician', '/technician/tickets', '/technician/tickets/assigned', '/technician/tickets/'.$assignedTicket->getId()] as $path) {
        assertSecurityMatrixStatus($kernel, null, $path, Response::HTTP_FOUND, 'anonymous '.$path);
    }
    foreach (['/admin', '/admin/users', '/admin/categories', '/admin/statistics'] as $path) {
        assertSecurityMatrixStatus($kernel, null, $path, Response::HTTP_FOUND, 'anonymous '.$path);
    }

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, null, 'ROLE_CLIENT', false, 'anonymous client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, null, 'ROLE_TECHNICIAN', false, 'anonymous technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, null, 'ROLE_ADMIN', false, 'anonymous admin area');

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $clientUser, 'ROLE_CLIENT', true, 'ROLE_CLIENT client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $clientUser, 'ROLE_TECHNICIAN', false, 'ROLE_CLIENT technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $clientUser, 'ROLE_ADMIN', false, 'ROLE_CLIENT admin area');

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianUser, 'ROLE_CLIENT', false, 'ROLE_TECHNICIAN client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianUser, 'ROLE_TECHNICIAN', true, 'ROLE_TECHNICIAN technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianUser, 'ROLE_ADMIN', false, 'ROLE_TECHNICIAN admin area');

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminUser, 'ROLE_CLIENT', false, 'ROLE_ADMIN client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminUser, 'ROLE_TECHNICIAN', false, 'ROLE_ADMIN technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminUser, 'ROLE_ADMIN', true, 'ROLE_ADMIN admin area');

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianClientUser, 'ROLE_CLIENT', true, 'ROLE_TECHNICIAN+ROLE_CLIENT client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianClientUser, 'ROLE_TECHNICIAN', true, 'ROLE_TECHNICIAN+ROLE_CLIENT technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $technicianClientUser, 'ROLE_ADMIN', false, 'ROLE_TECHNICIAN+ROLE_CLIENT admin area');

    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminTechnicianClientUser, 'ROLE_CLIENT', true, 'ROLE_ADMIN+ROLE_TECHNICIAN+ROLE_CLIENT client area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminTechnicianClientUser, 'ROLE_TECHNICIAN', true, 'ROLE_ADMIN+ROLE_TECHNICIAN+ROLE_CLIENT technician area');
    assertSecurityMatrixGranted($tokenStorage, $authorizationChecker, $adminTechnicianClientUser, 'ROLE_ADMIN', true, 'ROLE_ADMIN+ROLE_TECHNICIAN+ROLE_CLIENT admin area');

    assertSecurityMatrixLoginRedirectsTo($kernel, $clientUser, '/client');
    assertSecurityMatrixLoginRedirectsTo($kernel, $technicianUser, '/technician');
    assertSecurityMatrixLoginRedirectsTo($kernel, $adminUser, '/admin');
    assertSecurityMatrixLoginRedirectsTo($kernel, $technicianClientUser, '/technician');
    $logoutSession = assertSecurityMatrixLoginRedirectsTo($kernel, $adminTechnicianClientUser, '/admin');

    $logoutRequest = Request::create('/logout', 'GET');
    $logoutRequest->setSession($logoutSession);
    $logoutResponse = $kernel->handle($logoutRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureSecurityMatrix(Response::HTTP_FOUND === $logoutResponse->getStatusCode(), 'GET /logout must redirect.');
    ensureSecurityMatrix('/login' === parse_url((string) $logoutResponse->headers->get('Location'), PHP_URL_PATH), 'GET /logout must redirect to /login.');
    ensureSecurityMatrix(!$logoutSession->has('_security_main'), 'GET /logout must clear the authenticated session.');

    $invalidLoginSession = new Session(new MockArraySessionStorage());
    $invalidLoginRequest = Request::create('/login', 'POST', [
        '_username' => $clientUser->getEmail(),
        '_password' => 'wrong-password',
        '_csrf_token' => securityMatrixLoginCsrfToken($kernel, $invalidLoginSession),
    ], [], [], ['HTTP_ORIGIN' => 'http://localhost']);
    $invalidLoginRequest->setSession($invalidLoginSession);
    $invalidLoginResponse = $kernel->handle($invalidLoginRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureSecurityMatrix(Response::HTTP_FOUND === $invalidLoginResponse->getStatusCode(), 'Invalid credentials must redirect back to login.');
    ensureSecurityMatrix('/login' === parse_url((string) $invalidLoginResponse->headers->get('Location'), PHP_URL_PATH), 'Invalid credentials must not reach a dashboard.');

    $beforeTicketCount = (int) $entityManager->createQuery('SELECT COUNT(t.id) FROM App\Entity\Ticket t')->getSingleScalarResult();
    $beforeInterventionCount = (int) $entityManager->createQuery('SELECT COUNT(i.id) FROM App\Entity\Intervention i')->getSingleScalarResult();
    $beforeHistoryCount = (int) $entityManager->createQuery('SELECT COUNT(h.id) FROM App\Entity\TicketHistory h')->getSingleScalarResult();

    /** @var ClientTicketController $clientTicketController */
    $clientTicketController = $container->get(ClientTicketController::class);
    $ticketHistoryService = new TicketHistoryService($entityManager);

    securityMatrixSetUser($tokenStorage, $clientUser);
    $invalidClientPost = Request::create('/client/ticket/new', 'POST', [
        'ticket' => [
            'title' => 'Ticket sans CSRF',
            'description' => 'Ce ticket ne doit pas être créé.',
            '_token' => 'invalid-token',
        ],
    ]);
    $invalidClientPost->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($invalidClientPost);
    try {
        $invalidClientResponse = $clientTicketController->new($invalidClientPost, $entityManager, $ticketHistoryService);
    } finally {
        $requestStack->pop();
    }
    ensureSecurityMatrix(!$invalidClientResponse->isRedirect('/client/tickets'), 'Invalid client ticket CSRF must not redirect as a success.');
    assertSecurityMatrixNoMutation($entityManager, 'Invalid client ticket CSRF', $beforeTicketCount, $beforeInterventionCount, $beforeHistoryCount);

    /** @var TechnicianController $technicianController */
    $technicianController = $container->get(TechnicianController::class);
    /** @var CategoryRepository $categoryRepository */
    $categoryRepository = $entityManager->getRepository(Category::class);
    /** @var InterventionRepository $interventionRepository */
    $interventionRepository = $entityManager->getRepository(Intervention::class);
    /** @var TicketHistoryRepository $ticketHistoryRepository */
    $ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
    /** @var AIAnalysisRepository $aiAnalysisRepository */
    $aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);

    $assignedTicket = $entityManager->find(Ticket::class, $assignedTicket->getId());
    ensureSecurityMatrix($assignedTicket instanceof Ticket, 'Assigned ticket fixture must still exist.');
    $otherAssignedTicket = $entityManager->find(Ticket::class, $otherAssignedTicket->getId());
    ensureSecurityMatrix($otherAssignedTicket instanceof Ticket, 'Other assigned ticket fixture must still exist.');

    securityMatrixSetUser($tokenStorage, $otherTechnicianUser);
    assertSecurityMatrixThrowsAccessDenied(
        static fn () => $technicianController->updateStatus($assignedTicket, Request::create('/technician/tickets/'.$assignedTicket->getId().'/status', 'POST', ['status' => Ticket::STATUS_IN_PROGRESS, '_token' => 'invalid-token']), $ticketHistoryService),
        'Status update by a non-assigned technician',
    );
    assertSecurityMatrixThrowsAccessDenied(
        static fn () => $technicianController->updatePriority($assignedTicket, Request::create('/technician/tickets/'.$assignedTicket->getId().'/priority', 'POST', ['priority' => Ticket::PRIORITY_HIGH, '_token' => 'invalid-token']), $ticketHistoryService),
        'Priority update by a non-assigned technician',
    );
    assertSecurityMatrixThrowsAccessDenied(
        static fn () => $technicianController->updateCategory($assignedTicket, Request::create('/technician/tickets/'.$assignedTicket->getId().'/category', 'POST', ['category_id' => (string) $category->getId(), '_token' => 'invalid-token']), $categoryRepository, $ticketHistoryService),
        'Category update by a non-assigned technician',
    );
    assertSecurityMatrixThrowsAccessDenied(
        static fn () => $technicianController->createIntervention($assignedTicket, Request::create('/technician/tickets/'.$assignedTicket->getId().'/interventions', 'POST', ['intervention' => ['content' => 'Intervention interdite', '_token' => 'invalid-token']]), $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository, $entityManager),
        'Intervention creation by a non-assigned technician',
    );
    assertSecurityMatrixNoMutation($entityManager, 'Non-assigned technician POST attempts', $beforeTicketCount, $beforeInterventionCount, $beforeHistoryCount);

    $assignedTicket = $entityManager->find(Ticket::class, $assignedTicket->getId());
    ensureSecurityMatrix($assignedTicket instanceof Ticket, 'Assigned ticket fixture must still exist after ownership checks.');
    securityMatrixSetUser($tokenStorage, $technicianUser);
    $invalidStatusRequest = Request::create('/technician/tickets/'.$assignedTicket->getId().'/status', 'POST', ['status' => Ticket::STATUS_IN_PROGRESS, '_token' => 'invalid-token']);
    assertSecurityMatrixThrowsAccessDeniedWithRequest(
        $requestStack,
        $invalidStatusRequest,
        static fn () => $technicianController->updateStatus($assignedTicket, $invalidStatusRequest, $ticketHistoryService),
        'Status update with invalid CSRF',
    );
    $invalidPriorityRequest = Request::create('/technician/tickets/'.$assignedTicket->getId().'/priority', 'POST', ['priority' => Ticket::PRIORITY_HIGH, '_token' => 'invalid-token']);
    assertSecurityMatrixThrowsAccessDeniedWithRequest(
        $requestStack,
        $invalidPriorityRequest,
        static fn () => $technicianController->updatePriority($assignedTicket, $invalidPriorityRequest, $ticketHistoryService),
        'Priority update with invalid CSRF',
    );
    $invalidCategoryRequest = Request::create('/technician/tickets/'.$assignedTicket->getId().'/category', 'POST', ['category_id' => (string) $category->getId(), '_token' => 'invalid-token']);
    assertSecurityMatrixThrowsAccessDeniedWithRequest(
        $requestStack,
        $invalidCategoryRequest,
        static fn () => $technicianController->updateCategory($assignedTicket, $invalidCategoryRequest, $categoryRepository, $ticketHistoryService),
        'Category update with invalid CSRF',
    );

    $badInterventionRequest = Request::create('/technician/tickets/'.$assignedTicket->getId().'/interventions', 'POST', [
        'intervention' => [
            'content' => 'Intervention sans CSRF valide',
            '_token' => 'invalid-token',
        ],
    ]);
    $badInterventionRequest->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($badInterventionRequest);
    try {
        $badInterventionResponse = $technicianController->createIntervention($assignedTicket, $badInterventionRequest, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository, $entityManager);
    } finally {
        $requestStack->pop();
    }
    ensureSecurityMatrix(Response::HTTP_UNPROCESSABLE_ENTITY === $badInterventionResponse->getStatusCode(), 'Invalid intervention CSRF must render HTTP 422.');
    assertSecurityMatrixNoMutation($entityManager, 'Invalid technician CSRF attempts', $beforeTicketCount, $beforeInterventionCount, $beforeHistoryCount);

    $unauthorizedEmailResponse = securityMatrixEmailResponse($kernel, null);
    ensureSecurityMatrix(Response::HTTP_UNAUTHORIZED === $unauthorizedEmailResponse->getStatusCode(), 'Email API without Bearer must return 401.');
    ensureSecurityMatrix('Bearer realm="Pilot AI email ingestion"' === $unauthorizedEmailResponse->headers->get('WWW-Authenticate'), 'Email API 401 must include the Bearer challenge.');
    $invalidBearerResponse = securityMatrixEmailResponse($kernel, 'Bearer wrong-secret');
    ensureSecurityMatrix(Response::HTTP_UNAUTHORIZED === $invalidBearerResponse->getStatusCode(), 'Email API with wrong Bearer must return 401.');
    $csrfFreeEmailResponse = securityMatrixEmailResponse($kernel, 'Bearer security_matrix_secret_0123456789abcdef');
    ensureSecurityMatrix(Response::HTTP_UNAUTHORIZED !== $csrfFreeEmailResponse->getStatusCode(), 'Email API with valid Bearer must not fail as unauthenticated.');
    ensureSecurityMatrix(Response::HTTP_FORBIDDEN !== $csrfFreeEmailResponse->getStatusCode(), 'Email API with valid Bearer must not require web CSRF authorization.');
    $wrongContentTypeEmailResponse = securityMatrixEmailResponse($kernel, 'Bearer security_matrix_secret_0123456789abcdef', '{"sender":"client@example.test"}', 'text/plain');
    ensureSecurityMatrix(Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $wrongContentTypeEmailResponse->getStatusCode(), 'Email API must authenticate before preserving the JSON Content-Type validation.');
    assertSecurityMatrixNoMutation($entityManager, 'Email API authentication checks', $beforeTicketCount, $beforeInterventionCount, $beforeHistoryCount);

    echo "Security access matrix tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
