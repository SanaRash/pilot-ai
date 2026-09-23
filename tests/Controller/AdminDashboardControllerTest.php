<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Entity\Category;
use App\Entity\Ticket;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAdminDashboard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminDashboardUser(
    string $email,
    array $roles,
    string $firstname = 'Admin',
    string $lastname = 'Test',
): User {
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function adminDashboardTicket(
    User $client,
    string $title,
    string $status,
    string $priority,
    DateTimeImmutable $createdAt,
    ?User $assignedTo = null,
    ?Category $category = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description non affichée sur le dashboard admin')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function adminDashboardRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
{
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    return Request::create('/admin', 'GET');
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var AdminController $controller */
$controller = $container->get(AdminController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface $authorizationChecker */
$authorizationChecker = $controllerContainer->get('security.authorization_checker');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var UserRepository $userRepository */
$userRepository = $entityManager->getRepository(User::class);
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);

$connection->beginTransaction();
$testRequest = Request::create('/admin', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);
$testRequestPopped = false;

try {
    $openCountBefore = $ticketRepository->countByStatus(Ticket::STATUS_OPEN);
    $openUnassignedCountBefore = $ticketRepository->countOpenUnassignedTickets();
    $inProgressCountBefore = $ticketRepository->countByStatus(Ticket::STATUS_IN_PROGRESS);
    $resolvedCountBefore = $ticketRepository->countByStatus(Ticket::STATUS_RESOLVED);
    $closedCountBefore = $ticketRepository->countByStatus(Ticket::STATUS_CLOSED);
    $clientCountBefore = $userRepository->countByPersistedRole('ROLE_CLIENT');
    $technicianCountBefore = $userRepository->countByPersistedRole('ROLE_TECHNICIAN');
    $categoryCountBefore = $categoryRepository->count([]);

    $admin = adminDashboardUser('admin-dashboard@example.test', ['ROLE_ADMIN']);
    $client = adminDashboardUser(
        'admin-dashboard-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $technician = adminDashboardUser('admin-dashboard-technician@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Assigné');
    $adminOnly = adminDashboardUser('admin-dashboard-admin-only@example.test', ['ROLE_ADMIN'], 'AdminOnly');
    $clientRoleUser = adminDashboardUser('admin-dashboard-role-client@example.test', ['ROLE_CLIENT'], 'ClientRole');
    $technicianRoleUser = adminDashboardUser('admin-dashboard-role-tech@example.test', ['ROLE_TECHNICIAN'], 'TechRole');
    $category = (new Category())->setName('<script>alert("category")</script>');

    foreach ([$admin, $client, $technician, $adminOnly, $clientRoleUser, $technicianRoleUser, $category] as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();

    $sameCreatedAt = new DateTimeImmutable('2099-10-10 10:00:00');
    $openAssigned = adminDashboardTicket($client, '<script>alert("admin-ticket")</script>', Ticket::STATUS_OPEN, Ticket::PRIORITY_URGENT, $sameCreatedAt, $technician, $category);
    $openUnassignedSameDateHigherId = adminDashboardTicket($client, 'Ticket récent même date', Ticket::STATUS_OPEN, Ticket::PRIORITY_HIGH, $sameCreatedAt);
    $inProgress = adminDashboardTicket($client, 'Ticket admin en cours', Ticket::STATUS_IN_PROGRESS, Ticket::PRIORITY_MEDIUM, new DateTimeImmutable('2099-10-09 10:00:00'), $technician);
    $resolved = adminDashboardTicket($client, 'Ticket admin résolu', Ticket::STATUS_RESOLVED, Ticket::PRIORITY_LOW, new DateTimeImmutable('2099-10-08 10:00:00'));
    $closed = adminDashboardTicket($client, 'Ticket admin fermé', Ticket::STATUS_CLOSED, Ticket::PRIORITY_MEDIUM, new DateTimeImmutable('2099-10-07 10:00:00'));
    $olderOutOfTopFive = adminDashboardTicket($client, 'Ticket admin hors top cinq', Ticket::STATUS_OPEN, Ticket::PRIORITY_MEDIUM, new DateTimeImmutable('2099-10-06 10:00:00'));

    foreach ([$openAssigned, $openUnassignedSameDateHigherId, $inProgress, $resolved, $closed, $olderOutOfTopFive] as $ticket) {
        $entityManager->persist($ticket);
    }

    $entityManager->flush();

    ensureAdminDashboard($openCountBefore + 3 === $ticketRepository->countByStatus(Ticket::STATUS_OPEN), 'OPEN tickets must be counted exactly.');
    ensureAdminDashboard($openUnassignedCountBefore + 2 === $ticketRepository->countOpenUnassignedTickets(), 'OPEN unassigned tickets must be counted exactly.');
    ensureAdminDashboard($inProgressCountBefore + 1 === $ticketRepository->countByStatus(Ticket::STATUS_IN_PROGRESS), 'IN_PROGRESS tickets must be counted exactly.');
    ensureAdminDashboard($resolvedCountBefore + 1 === $ticketRepository->countByStatus(Ticket::STATUS_RESOLVED), 'RESOLVED tickets must be counted exactly.');
    ensureAdminDashboard($closedCountBefore + 1 === $ticketRepository->countByStatus(Ticket::STATUS_CLOSED), 'CLOSED tickets must be counted exactly.');
    ensureAdminDashboard($clientCountBefore + 2 === $userRepository->countByPersistedRole('ROLE_CLIENT'), 'Persisted ROLE_CLIENT users must be counted exactly.');
    ensureAdminDashboard($technicianCountBefore + 2 === $userRepository->countByPersistedRole('ROLE_TECHNICIAN'), 'Persisted ROLE_TECHNICIAN users must be counted exactly.');
    ensureAdminDashboard($categoryCountBefore + 1 === $categoryRepository->count([]), 'Categories must be counted exactly.');

    $recentTickets = $ticketRepository->findRecentForAdmin();
    ensureAdminDashboard(5 === count($recentTickets), 'Recent tickets must be limited to 5.');
    ensureAdminDashboard([$openUnassignedSameDateHigherId, $openAssigned, $inProgress, $resolved, $closed] === $recentTickets, 'Recent tickets must be ordered by createdAt DESC then id DESC.');
    ensureAdminDashboard(!in_array($olderOutOfTopFive, $recentTickets, true), 'The sixth recent ticket must not be returned.');

    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $userCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM "user"');
    $categoryCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM category');

    $response = $controller->index($ticketRepository, $userRepository, $categoryRepository);
    $html = $response->getContent();

    ensureAdminDashboard(Response::HTTP_OK === $response->getStatusCode(), 'The admin dashboard must render with HTTP 200.');
    ensureAdminDashboard(str_contains($html, 'Dashboard admin'), 'The dashboard title is missing.');
    ensureAdminDashboard(!str_contains($html, 'Hello AdminController'), 'The Symfony placeholder must be removed.');
    ensureAdminDashboard(str_contains($html, 'Tickets ouverts'), 'OPEN metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Tickets non assignés ouverts'), 'OPEN unassigned metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Tickets en cours'), 'IN_PROGRESS metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Tickets résolus'), 'RESOLVED metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Tickets fermés'), 'CLOSED metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Clients'), 'Client metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Techniciens'), 'Technician metric label is missing.');
    ensureAdminDashboard(str_contains($html, 'Catégories'), 'Category metric label is missing.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $openCountBefore + 3)), 'OPEN metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $openUnassignedCountBefore + 2)), 'OPEN unassigned metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $inProgressCountBefore + 1)), 'IN_PROGRESS metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $resolvedCountBefore + 1)), 'RESOLVED metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $closedCountBefore + 1)), 'CLOSED metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $clientCountBefore + 2)), 'Client metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $technicianCountBefore + 2)), 'Technician metric value is incorrect.');
    ensureAdminDashboard(str_contains($html, sprintf('admin-dashboard__metric-value">%d<', $categoryCountBefore + 1)), 'Category metric value is incorrect.');

    ensureAdminDashboard(str_contains($html, 'Tickets récents'), 'Recent tickets section is missing.');
    ensureAdminDashboard(str_contains($html, 'Ticket récent même date'), 'A recent ticket is missing.');
    ensureAdminDashboard(str_contains($html, '&lt;script&gt;alert(&quot;admin-ticket&quot;)&lt;/script&gt;'), 'Ticket title must be escaped.');
    ensureAdminDashboard(!str_contains($html, '<script>alert("admin-ticket")</script>'), 'An unescaped ticket title was rendered.');
    ensureAdminDashboard(!str_contains($html, 'Ticket admin hors top cinq'), 'More than five recent tickets were rendered.');
    ensureAdminDashboard(str_contains($html, 'Ouvert'), 'OPEN status must be translated.');
    ensureAdminDashboard(str_contains($html, 'En cours'), 'IN_PROGRESS status must be translated.');
    ensureAdminDashboard(str_contains($html, 'Résolu'), 'RESOLVED status must be translated.');
    ensureAdminDashboard(str_contains($html, 'Fermé'), 'CLOSED status must be translated.');
    ensureAdminDashboard(str_contains($html, 'Urgente'), 'URGENT priority must be translated.');
    ensureAdminDashboard(str_contains($html, 'Haute'), 'HIGH priority must be translated.');
    ensureAdminDashboard(str_contains($html, 'Moyenne'), 'MEDIUM priority must be translated.');
    ensureAdminDashboard(str_contains($html, 'Basse'), 'LOW priority must be translated.');
    ensureAdminDashboard(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category names must be escaped.');
    ensureAdminDashboard(str_contains($html, 'Non catégorisé'), 'Missing category must be displayed.');
    ensureAdminDashboard(str_contains($html, '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client'), 'Client identity must be escaped.');
    ensureAdminDashboard(str_contains($html, 'Tech Assigné'), 'Assigned technician identity must be displayed.');
    ensureAdminDashboard(str_contains($html, 'Non assigné'), 'Unassigned tickets must be displayed.');
    ensureAdminDashboard(str_contains($html, '10/10/2099 10:00'), 'Ticket dates must use d/m/Y H:i.');
    ensureAdminDashboard(!str_contains($html, '<form'), 'The admin dashboard must not render any form.');
    ensureAdminDashboard(!str_contains($html, 'method="post"'), 'The admin dashboard must not render POST forms.');
    ensureAdminDashboard(!str_contains($html, 'Modifier le statut'), 'The admin dashboard must not expose status mutation.');
    ensureAdminDashboard(!str_contains($html, 'Modifier la priorité'), 'The admin dashboard must not expose priority mutation.');
    ensureAdminDashboard(!str_contains($html, 'Modifier la catégorie'), 'The admin dashboard must not expose category mutation.');
    ensureAdminDashboard(!str_contains($html, 'M&#039;assigner ce ticket'), 'The admin dashboard must not expose assignment.');
    ensureAdminDashboard(!str_contains($html, 'Créer'), 'The admin dashboard must not expose creation actions.');
    ensureAdminDashboard(!str_contains($html, 'Supprimer'), 'The admin dashboard must not expose deletion actions.');
    ensureAdminDashboard($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the admin dashboard must not persist tickets.');
    ensureAdminDashboard($userCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM "user"'), 'Rendering the admin dashboard must not persist users.');
    ensureAdminDashboard($categoryCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM category'), 'Rendering the admin dashboard must not persist categories.');

    $sameDateHigherIdPosition = strpos($html, 'Ticket récent même date');
    $sameDateOlderIdPosition = strpos($html, '&lt;script&gt;alert(&quot;admin-ticket&quot;)&lt;/script&gt;');
    $inProgressPosition = strpos($html, 'Ticket admin en cours');
    ensureAdminDashboard(
        false !== $sameDateHigherIdPosition
        && false !== $sameDateOlderIdPosition
        && $sameDateHigherIdPosition < $sameDateOlderIdPosition,
        'Recent tickets are not ordered by id DESC when createdAt is equal.',
    );
    ensureAdminDashboard(
        false !== $sameDateOlderIdPosition
        && false !== $inProgressPosition
        && $sameDateOlderIdPosition < $inProgressPosition,
        'Recent tickets are not ordered by createdAt DESC.',
    );

    $emptyHtml = $twig->render('admin/index.html.twig', [
        'open_ticket_count' => 0,
        'open_unassigned_ticket_count' => 0,
        'in_progress_ticket_count' => 0,
        'resolved_ticket_count' => 0,
        'closed_ticket_count' => 0,
        'client_count' => 0,
        'technician_count' => 0,
        'category_count' => 0,
        'recent_tickets' => [],
        'status_labels' => [
            Ticket::STATUS_OPEN => 'Ouvert',
            Ticket::STATUS_IN_PROGRESS => 'En cours',
            Ticket::STATUS_RESOLVED => 'Résolu',
            Ticket::STATUS_CLOSED => 'Fermé',
        ],
        'priority_labels' => [
            Ticket::PRIORITY_LOW => 'Basse',
            Ticket::PRIORITY_MEDIUM => 'Moyenne',
            Ticket::PRIORITY_HIGH => 'Haute',
            Ticket::PRIORITY_URGENT => 'Urgente',
        ],
    ]);
    ensureAdminDashboard(str_contains($emptyHtml, 'Aucun ticket récent à afficher.'), 'The empty state is missing.');

    $requestStack->pop();
    $testRequestPopped = true;

    $tokenStorage->setToken(null);
    ensureAdminDashboard(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'An unauthenticated user must not have ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($clientRoleUser, 'main', $clientRoleUser->getRoles()));
    ensureAdminDashboard(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_CLIENT must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($technicianRoleUser, 'main', $technicianRoleUser->getRoles()));
    ensureAdminDashboard(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_TECHNICIAN must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    ensureAdminDashboard($authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_ADMIN must grant access to the admin dashboard.');

    $httpResponse = $kernel->handle(adminDashboardRequest($tokenStorage, $admin), HttpKernelInterface::SUB_REQUEST);
    ensureAdminDashboard(Response::HTTP_OK === $httpResponse->getStatusCode(), sprintf('Authenticated GET /admin must return 200 (got %d).', $httpResponse->getStatusCode()));

    echo "Admin dashboard tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    if (!$testRequestPopped) {
        $requestStack->pop();
    }

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
