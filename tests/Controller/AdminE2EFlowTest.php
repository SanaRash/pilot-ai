<?php

declare(strict_types=1);

use App\Entity\Category;
use App\Entity\Ticket;
use App\Controller\AdminController;
use App\Entity\User;
use App\Kernel;
use App\Repository\CategoryRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAdminE2E(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminE2EUser(
    string $email,
    array $roles,
    string $firstname,
    string $lastname,
    DateTimeImmutable $createdAt,
    bool $isActive = true,
    string $plainPassword = 'CorrectHorseBatteryStaple123!',
): User {
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles($roles)
        ->setPassword(password_hash($plainPassword, PASSWORD_DEFAULT))
        ->setIsActive($isActive)
        ->setCreatedAt($createdAt);
}

function adminE2ETicket(
    User $client,
    string $title,
    string $status,
    string $priority,
    DateTimeImmutable $createdAt,
    ?Category $category = null,
    ?User $assignedTo = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description de test E2E admin non affichée.')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setCategory($category)
        ->setAssignedTo($assignedTo);
}

function adminE2EHandle(Kernel $kernel, string $path, string $method = 'GET', ?Session $session = null): Response
{
    $request = Request::create($path, $method);

    if (null !== $session) {
        $request->setSession($session);
    }

    return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
}


function adminE2ERender(mixed $requestStack, Request $request, callable $callback): Response
{
    $requestStack->push($request);

    try {
        return $callback();
    } finally {
        $requestStack->pop();
    }
}

function adminE2EAuthenticatedRequest(TokenStorageInterface $tokenStorage, User $user, string $path): Request
{
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    $request = Request::create($path, 'GET');
    $request->setSession(new Session(new MockArraySessionStorage()));

    return $request;
}

function adminE2ELoginCsrfToken(Kernel $kernel, Session $session): string
{
    $request = Request::create('/login', 'GET');
    $request->setSession($session);
    $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);

    ensureAdminE2E(Response::HTTP_OK === $response->getStatusCode(), 'GET /login must return 200 before login.');
    preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $response->getContent(), $matches);
    ensureAdminE2E(isset($matches[1]) && '' !== $matches[1], 'Login CSRF token must be rendered.');

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function adminE2ESessionFor(User $user): Session
{
    $session = new Session(new MockArraySessionStorage());
    $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', $user->getRoles())));

    return $session;
}

function adminE2ELogin(Kernel $kernel, User $user, string $expectedPath): Session
{
    $session = new Session(new MockArraySessionStorage());
    $csrfToken = adminE2ELoginCsrfToken($kernel, $session);
    $request = Request::create('/login', 'POST', [
        '_username' => $user->getEmail(),
        '_password' => 'CorrectHorseBatteryStaple123!',
        '_csrf_token' => $csrfToken,
    ], [], [], ['HTTP_ORIGIN' => 'http://localhost']);
    $request->setSession($session);

    $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);

    ensureAdminE2E(Response::HTTP_FOUND === $response->getStatusCode(), sprintf('Login for %s must redirect.', $user->getEmail()));
    ensureAdminE2E($expectedPath === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), sprintf(
        'Login for %s must redirect to %s, got %s.',
        $user->getEmail(),
        $expectedPath,
        (string) $response->headers->get('Location'),
    ));
    ensureAdminE2E($session->has('_security_main'), sprintf('Login for %s must store an authenticated session.', $user->getEmail()));

    return $session;
}

function adminE2ECounts(Connection $connection): array
{
    return [
        'users' => (int) $connection->fetchOne('SELECT COUNT(*) FROM "user"'),
        'tickets' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'),
        'categories' => (int) $connection->fetchOne('SELECT COUNT(*) FROM category'),
        'interventions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention'),
        'ticket_history' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'),
        'ai_analysis' => (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis'),
    ];
}

function adminE2EAssertCountsUnchanged(Connection $connection, array $snapshot, string $label): void
{
    ensureAdminE2E($snapshot === adminE2ECounts($connection), $label.' must not mutate persisted data.');
}

function adminE2EAssertReadOnlyHtml(string $html, string $label, array $forbiddenLabels = []): void
{
    ensureAdminE2E(!str_contains($html, '<form'), $label.' must not render forms.');
    ensureAdminE2E(!str_contains($html, 'method="post"'), $label.' must not render POST forms.');

    foreach ($forbiddenLabels as $forbiddenLabel) {
        ensureAdminE2E(!str_contains($html, $forbiddenLabel), sprintf('%s must not expose action "%s".', $label, $forbiddenLabel));
    }
}


function adminE2EAssertNotAdmin(TokenStorageInterface $tokenStorage, AuthorizationCheckerInterface $authorizationChecker, User $user, string $label): void
{
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    ensureAdminE2E(!$authorizationChecker->isGranted('ROLE_ADMIN'), $label.' must not be granted ROLE_ADMIN.');
}

function adminE2EAssertForbidden(Kernel $kernel, Session $session, string $path, string $label): void
{
    $response = adminE2EHandle($kernel, $path, 'GET', $session);
    ensureAdminE2E(Response::HTTP_FORBIDDEN === $response->getStatusCode(), sprintf(
        '%s must return 403 for %s, got %d.',
        $label,
        $path,
        $response->getStatusCode(),
    ));
}

function adminE2EAssertAnonymousRedirect(Kernel $kernel, string $path): void
{
    $response = adminE2EHandle($kernel, $path, 'GET', new Session(new MockArraySessionStorage()));
    ensureAdminE2E(Response::HTTP_FOUND === $response->getStatusCode(), sprintf('Anonymous GET %s must redirect.', $path));
    ensureAdminE2E('/login' === parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), sprintf('Anonymous GET %s must redirect to /login.', $path));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var AdminController $adminController */
$adminController = $container->get(AdminController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($adminController);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var AuthorizationCheckerInterface $authorizationChecker */
$authorizationChecker = $controllerContainer->get('security.authorization_checker');
$requestStack = $controllerContainer->get('request_stack');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var UserRepository $userRepository */
$userRepository = $entityManager->getRepository(User::class);
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);

$connection->beginTransaction();

try {
    $admin = adminE2EUser('admin-e2e-admin@example.test', ['ROLE_ADMIN'], 'Admin', 'E2E', new DateTimeImmutable('2099-12-01 10:00:00'));
    $client = adminE2EUser('admin-e2e-client@example.test', ['ROLE_CLIENT'], 'Client', 'E2E', new DateTimeImmutable('2099-12-02 10:00:00'));
    $technician = adminE2EUser('admin-e2e-technician@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Assigné', new DateTimeImmutable('2099-12-03 10:00:00'));
    $inactiveUser = adminE2EUser('admin-e2e-inactive@example.test', ['ROLE_CLIENT'], 'Inactive', 'User', new DateTimeImmutable('2099-12-04 10:00:00'), false);
    $unknownRoleUser = adminE2EUser('admin-e2e-unknown-role@example.test', ['ROLE_SUPPORT_CUSTOM'], 'Unknown', 'Role', new DateTimeImmutable('2099-12-05 10:00:00'));

    $categoryTwoTickets = (new Category())->setName('000 Admin E2E catégorie & multi');
    $categoryOneTicket = (new Category())->setName('001 Admin E2E catégorie simple');
    $categoryWithoutTicket = (new Category())->setName('002 Admin E2E catégorie vide <script>alert("cat")</script>');

    foreach ([$admin, $client, $technician, $inactiveUser, $unknownRoleUser, $categoryTwoTickets, $categoryOneTicket, $categoryWithoutTicket] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $today = new DateTimeImmutable('today');
    $futureRecentDate = new DateTimeImmutable('2099-12-10 10:00:00');
    $tickets = [
        adminE2ETicket($client, 'Admin E2E récent 1 urgent assigné', Ticket::STATUS_OPEN, Ticket::PRIORITY_URGENT, $futureRecentDate, $categoryTwoTickets, $technician),
        adminE2ETicket($client, 'Admin E2E récent 2 high non assigné', Ticket::STATUS_OPEN, Ticket::PRIORITY_HIGH, $futureRecentDate, null, null),
        adminE2ETicket($client, 'Admin E2E récent 3 en cours', Ticket::STATUS_IN_PROGRESS, Ticket::PRIORITY_MEDIUM, $futureRecentDate->modify('-1 minute'), $categoryTwoTickets, $technician),
        adminE2ETicket($client, 'Admin E2E récent 4 résolu', Ticket::STATUS_RESOLVED, Ticket::PRIORITY_LOW, $futureRecentDate->modify('-2 minutes'), $categoryOneTicket, null),
        adminE2ETicket($client, 'Admin E2E récent 5 fermé', Ticket::STATUS_CLOSED, Ticket::PRIORITY_MEDIUM, $futureRecentDate->modify('-3 minutes'), null, null),
        adminE2ETicket($client, 'Admin E2E récent 6 hors dashboard', Ticket::STATUS_OPEN, Ticket::PRIORITY_LOW, $futureRecentDate->modify('-4 minutes'), $categoryOneTicket, null),
        adminE2ETicket($client, 'Admin E2E timeline today', Ticket::STATUS_OPEN, Ticket::PRIORITY_URGENT, $today->setTime(9, 0), $categoryTwoTickets, null),
        adminE2ETicket($client, 'Admin E2E timeline J-6', Ticket::STATUS_RESOLVED, Ticket::PRIORITY_MEDIUM, $today->modify('-6 days')->setTime(11, 0), null, null),
        adminE2ETicket($client, 'Admin E2E old timeline excluded', Ticket::STATUS_CLOSED, Ticket::PRIORITY_LOW, $today->modify('-7 days')->setTime(8, 0), $categoryOneTicket, null),
    ];

    foreach ($tickets as $ticket) {
        $entityManager->persist($ticket);
    }
    $entityManager->flush();

    $snapshotBeforeAdminNavigation = adminE2ECounts($connection);

    $adminSession = adminE2ELogin($kernel, $admin, '/admin');

    $dashboardResponse = adminE2ERender(
        $requestStack,
        adminE2EAuthenticatedRequest($tokenStorage, $admin, '/admin'),
        static fn (): Response => $adminController->index($ticketRepository, $userRepository, $categoryRepository),
    );
    ensureAdminE2E(Response::HTTP_OK === $dashboardResponse->getStatusCode(), 'GET /admin must return 200 for ROLE_ADMIN.');
    $dashboardHtml = (string) $dashboardResponse->getContent();
    ensureAdminE2E(str_contains($dashboardHtml, 'Dashboard admin'), 'Admin dashboard title must be displayed.');
    foreach (['Tickets ouverts', 'Tickets non assignés ouverts', 'Tickets en cours', 'Tickets résolus', 'Tickets fermés', 'Clients', 'Techniciens', 'Catégories'] as $label) {
        ensureAdminE2E(str_contains($dashboardHtml, $label), sprintf('Dashboard metric "%s" must be displayed.', $label));
    }
    foreach (['Admin E2E récent 1 urgent assigné', 'Admin E2E récent 2 high non assigné', 'Admin E2E récent 3 en cours', 'Admin E2E récent 4 résolu', 'Admin E2E récent 5 fermé'] as $title) {
        ensureAdminE2E(str_contains($dashboardHtml, $title), sprintf('Recent ticket "%s" must be displayed on dashboard.', $title));
    }
    ensureAdminE2E(!str_contains($dashboardHtml, 'Admin E2E récent 6 hors dashboard'), 'Dashboard recent tickets must be limited to 5.');
    foreach (['Ouvert', 'En cours', 'Résolu', 'Fermé', 'Urgente', 'Haute', 'Moyenne', 'Basse', 'Non catégorisé', 'Client E2E', 'Tech Assigné', 'Non assigné'] as $label) {
        ensureAdminE2E(str_contains($dashboardHtml, $label), sprintf('Dashboard must display "%s".', $label));
    }
    adminE2EAssertReadOnlyHtml($dashboardHtml, 'Admin dashboard');

    $usersResponse = adminE2ERender(
        $requestStack,
        adminE2EAuthenticatedRequest($tokenStorage, $admin, '/admin/users'),
        static fn (): Response => $adminController->users($userRepository),
    );
    ensureAdminE2E(Response::HTTP_OK === $usersResponse->getStatusCode(), 'GET /admin/users must return 200 for ROLE_ADMIN.');
    $usersHtml = (string) $usersResponse->getContent();
    ensureAdminE2E(str_contains($usersHtml, 'Utilisateurs'), 'Admin users title must be displayed.');
    foreach (['admin-e2e-admin@example.test', 'admin-e2e-client@example.test', 'admin-e2e-technician@example.test', 'admin-e2e-inactive@example.test', 'admin-e2e-unknown-role@example.test'] as $email) {
        ensureAdminE2E(str_contains($usersHtml, $email), sprintf('User %s must be listed.', $email));
    }
    foreach (['Client', 'Technicien', 'Administrateur', 'Actif', 'Inactif', 'ROLE_SUPPORT_CUSTOM'] as $label) {
        ensureAdminE2E(str_contains($usersHtml, $label), sprintf('Users page must display "%s".', $label));
    }
    ensureAdminE2E(!str_contains($usersHtml, 'ROLE_USER'), 'Users page must not display automatic ROLE_USER when it is not persisted.');
    ensureAdminE2E(
        strpos($usersHtml, 'admin-e2e-unknown-role@example.test') < strpos($usersHtml, 'admin-e2e-inactive@example.test')
        && strpos($usersHtml, 'admin-e2e-inactive@example.test') < strpos($usersHtml, 'admin-e2e-technician@example.test')
        && strpos($usersHtml, 'admin-e2e-technician@example.test') < strpos($usersHtml, 'admin-e2e-client@example.test'),
        'Users page must follow createdAt DESC then id DESC ordering for E2E users.',
    );
    adminE2EAssertReadOnlyHtml($usersHtml, 'Admin users page', ['Créer', 'Modifier', 'Supprimer', 'Désactiver', 'Activer', 'Reset', 'Mot de passe']);

    $categoriesResponse = adminE2ERender(
        $requestStack,
        adminE2EAuthenticatedRequest($tokenStorage, $admin, '/admin/categories'),
        static fn (): Response => $adminController->categories($categoryRepository),
    );
    ensureAdminE2E(Response::HTTP_OK === $categoriesResponse->getStatusCode(), 'GET /admin/categories must return 200 for ROLE_ADMIN.');
    $categoriesHtml = (string) $categoriesResponse->getContent();
    foreach (['000 Admin E2E catégorie &amp; multi', '001 Admin E2E catégorie simple', '002 Admin E2E catégorie vide &lt;script&gt;alert(&quot;cat&quot;)&lt;/script&gt;'] as $categoryName) {
        ensureAdminE2E(str_contains($categoriesHtml, $categoryName), sprintf('Category "%s" must be displayed and escaped.', $categoryName));
    }
    ensureAdminE2E(strpos($categoriesHtml, '000 Admin E2E catégorie') < strpos($categoriesHtml, '001 Admin E2E catégorie'), 'Categories must be ordered by name ASC.');
    ensureAdminE2E(strpos($categoriesHtml, '001 Admin E2E catégorie') < strpos($categoriesHtml, '002 Admin E2E catégorie'), 'Categories must be ordered by name ASC then id ASC.');
    ensureAdminE2E(str_contains($categoriesHtml, '<td>#'.$categoryTwoTickets->getId().'</td>'), 'Category ids must be rendered.');
    ensureAdminE2E(str_contains($categoriesHtml, '>3<'), 'Category with several tickets must display its ticket count.');
    ensureAdminE2E(str_contains($categoriesHtml, '>3<'), 'Category with one or more tickets must display counts.');
    ensureAdminE2E(str_contains($categoriesHtml, '>0<'), 'Category without ticket must display 0.');
    adminE2EAssertReadOnlyHtml($categoriesHtml, 'Admin categories page', ['Créer', 'Modifier', 'Supprimer', 'Fusionner', 'Archiver']);

    $statisticsResponse = adminE2ERender(
        $requestStack,
        adminE2EAuthenticatedRequest($tokenStorage, $admin, '/admin/statistics'),
        static fn (): Response => $adminController->statistics($ticketRepository),
    );
    ensureAdminE2E(Response::HTTP_OK === $statisticsResponse->getStatusCode(), 'GET /admin/statistics must return 200 for ROLE_ADMIN.');
    $statisticsHtml = (string) $statisticsResponse->getContent();
    foreach (['Statistiques', 'Volume total', 'Total des tickets', 'Répartition par statut', 'Répartition par priorité', 'Répartition par catégorie', 'Tickets créés sur les 7 derniers jours'] as $label) {
        ensureAdminE2E(str_contains($statisticsHtml, $label), sprintf('Statistics page must display "%s".', $label));
    }
    foreach (['Ouvert', 'En cours', 'Résolu', 'Fermé', 'Basse', 'Moyenne', 'Haute', 'Urgente', 'Non catégorisé'] as $label) {
        ensureAdminE2E(str_contains($statisticsHtml, $label), sprintf('Statistics page must display "%s".', $label));
    }
    ensureAdminE2E(7 === substr_count($statisticsHtml, '<div class="admin-statistics__bar-fill"'), 'Statistics timeline must render exactly 7 days.');
    $oldestTimelineDate = $today->modify('-6 days')->format('d/m/Y');
    $todayTimelineDate = $today->format('d/m/Y');
    ensureAdminE2E(strpos($statisticsHtml, $oldestTimelineDate) < strpos($statisticsHtml, $todayTimelineDate), 'Statistics timeline must be ordered from oldest to newest.');
    ensureAdminE2E(str_contains($statisticsHtml, '>0<'), 'Statistics timeline must display 0 for days without tickets when present.');
    adminE2EAssertReadOnlyHtml($statisticsHtml, 'Admin statistics page', ['Exporter']);

    adminE2EAssertCountsUnchanged($connection, $snapshotBeforeAdminNavigation, 'Admin E2E navigation');

    $securityConfig = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
    ensureAdminE2E([
        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/technician', 'roles' => 'ROLE_TECHNICIAN'],
        ['path' => '^/client', 'roles' => 'ROLE_CLIENT'],
    ] === ($securityConfig['security']['access_control'] ?? null), 'The access_control role rules or order changed.');

    adminE2ELogin($kernel, $client, '/client');
    adminE2EAssertNotAdmin($tokenStorage, $authorizationChecker, $client, 'ROLE_CLIENT');

    adminE2ELogin($kernel, $technician, '/technician');
    adminE2EAssertNotAdmin($tokenStorage, $authorizationChecker, $technician, 'ROLE_TECHNICIAN');

    foreach (['/admin', '/admin/users', '/admin/categories', '/admin/statistics'] as $path) {
        adminE2EAssertAnonymousRedirect($kernel, $path);
    }

    $logoutResponse = adminE2EHandle($kernel, '/logout', 'GET', $adminSession);
    ensureAdminE2E(Response::HTTP_FOUND === $logoutResponse->getStatusCode(), 'GET /logout must redirect.');
    ensureAdminE2E('/login' === parse_url((string) $logoutResponse->headers->get('Location'), PHP_URL_PATH), 'Logout must redirect to /login.');
    ensureAdminE2E(!$adminSession->has('_security_main'), 'Logout must invalidate the admin security session.');

    $afterLogoutResponse = adminE2EHandle($kernel, '/admin', 'GET', $adminSession);
    ensureAdminE2E(Response::HTTP_FOUND === $afterLogoutResponse->getStatusCode(), 'Logged-out admin session must be redirected away from /admin.');
    ensureAdminE2E('/login' === parse_url((string) $afterLogoutResponse->headers->get('Location'), PHP_URL_PATH), 'Logged-out admin session must redirect to /login.');

    adminE2EAssertCountsUnchanged($connection, $snapshotBeforeAdminNavigation, 'Admin E2E full flow');

    echo "Admin E2E flow tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
