<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\TicketRepository;
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

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAdminStatistics(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminStatisticsUser(string $email, array $roles, string $firstname = 'Admin'): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname('Test')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function adminStatisticsTicket(
    User $client,
    string $title,
    string $status,
    string $priority,
    DateTimeImmutable $createdAt,
    ?Category $category = null,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description non affichée sur les statistiques admin')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setCategory($category);
}

function adminStatisticsRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
{
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    return Request::create('/admin/statistics', 'GET');
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
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);

$connection->beginTransaction();
$testRequest = Request::create('/admin/statistics', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);
$testRequestPopped = false;

try {
    $admin = adminStatisticsUser('admin-statistics-admin@example.test', ['ROLE_ADMIN']);
    $clientRole = adminStatisticsUser('admin-statistics-client-role@example.test', ['ROLE_CLIENT'], 'ClientRole');
    $technicianRole = adminStatisticsUser('admin-statistics-tech-role@example.test', ['ROLE_TECHNICIAN'], 'TechRole');
    $client = adminStatisticsUser('admin-statistics-client@example.test', ['ROLE_CLIENT'], 'Client');

    $categoryAlpha = (new Category())->setName('Alpha <script>alert("category")</script>');
    $categoryBeta = (new Category())->setName('Beta');
    $categoryUnused = (new Category())->setName('Unused category must stay absent');

    foreach ([$admin, $clientRole, $technicianRole, $client, $categoryAlpha, $categoryBeta, $categoryUnused] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $today = new DateTimeImmutable('today');
    $tickets = [
        adminStatisticsTicket($client, 'Open urgent alpha', Ticket::STATUS_OPEN, Ticket::PRIORITY_URGENT, $today->setTime(9, 0), $categoryAlpha),
        adminStatisticsTicket($client, 'In progress high alpha', Ticket::STATUS_IN_PROGRESS, Ticket::PRIORITY_HIGH, $today->setTime(10, 0), $categoryAlpha),
        adminStatisticsTicket($client, 'Resolved medium beta', Ticket::STATUS_RESOLVED, Ticket::PRIORITY_MEDIUM, $today->modify('-1 day')->setTime(11, 0), $categoryBeta),
        adminStatisticsTicket($client, 'Closed low null', Ticket::STATUS_CLOSED, Ticket::PRIORITY_LOW, $today->modify('-3 days')->setTime(12, 0)),
        adminStatisticsTicket($client, 'Open medium null', Ticket::STATUS_OPEN, Ticket::PRIORITY_MEDIUM, $today->modify('-6 days')->setTime(13, 0)),
        adminStatisticsTicket($client, 'Old excluded from timeline', Ticket::STATUS_OPEN, Ticket::PRIORITY_LOW, $today->modify('-7 days')->setTime(8, 0), $categoryBeta),
    ];

    foreach ($tickets as $ticket) {
        $entityManager->persist($ticket);
    }
    $entityManager->flush();

    $tokenStorage->setToken(null);
    ensureAdminStatistics(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'An unauthenticated user must not have ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($clientRole, 'main', $clientRole->getRoles()));
    ensureAdminStatistics(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_CLIENT must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($technicianRole, 'main', $technicianRole->getRoles()));
    ensureAdminStatistics(!$authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_TECHNICIAN must not grant ROLE_ADMIN.');

    $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    ensureAdminStatistics($authorizationChecker->isGranted('ROLE_ADMIN'), 'ROLE_ADMIN must grant access to /admin/statistics.');

    $adminResponse = $kernel->handle(adminStatisticsRequest($tokenStorage, $admin), HttpKernelInterface::SUB_REQUEST);
    ensureAdminStatistics(Response::HTTP_OK === $adminResponse->getStatusCode(), sprintf('ROLE_ADMIN GET /admin/statistics must return 200 (got %d).', $adminResponse->getStatusCode()));

    $priorityCounts = $ticketRepository->countByPriorityForAdminStats();
    ensureAdminStatistics(($priorityCounts[Ticket::PRIORITY_LOW] ?? 0) >= 2, 'LOW priority count must include matching tickets.');
    ensureAdminStatistics(($priorityCounts[Ticket::PRIORITY_MEDIUM] ?? 0) >= 2, 'MEDIUM priority count must include matching tickets.');
    ensureAdminStatistics(($priorityCounts[Ticket::PRIORITY_HIGH] ?? 0) >= 1, 'HIGH priority count must include matching tickets.');
    ensureAdminStatistics(($priorityCounts[Ticket::PRIORITY_URGENT] ?? 0) >= 1, 'URGENT priority count must include matching tickets.');

    $categoryCounts = $ticketRepository->countByCategoryForAdminStats();
    $categoryLabels = array_column($categoryCounts, 'label');
    ensureAdminStatistics(in_array('Alpha <script>alert("category")</script>', $categoryLabels, true), 'Category Alpha must be aggregated.');
    ensureAdminStatistics(in_array('Beta', $categoryLabels, true), 'Category Beta must be aggregated.');
    ensureAdminStatistics(in_array('Non catégorisé', $categoryLabels, true), 'Uncategorized tickets must be grouped.');
    ensureAdminStatistics(!in_array('Unused category must stay absent', $categoryLabels, true), 'Unused categories must not be included in statistics.');

    $createdByDayCounts = $ticketRepository->countCreatedByDayForAdminStats($today->modify('-6 days'), $today->modify('+1 day'));
    ensureAdminStatistics(($createdByDayCounts[$today->format('Y-m-d')] ?? 0) >= 2, 'Multiple tickets on the same day must be aggregated.');
    ensureAdminStatistics(($createdByDayCounts[$today->modify('-7 days')->format('Y-m-d')] ?? 0) === 0, 'Tickets outside the 7-day window must be excluded.');

    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->statistics($ticketRepository);
    $html = $response->getContent();

    ensureAdminStatistics(Response::HTTP_OK === $response->getStatusCode(), 'The admin statistics page must render with HTTP 200.');
    ensureAdminStatistics(str_contains($html, 'Statistiques'), 'The statistics title is missing.');
    ensureAdminStatistics(str_contains($html, 'Volume total'), 'The total volume section is missing.');
    ensureAdminStatistics(str_contains($html, 'Total des tickets'), 'The total ticket label is missing.');
    ensureAdminStatistics(str_contains($html, 'Répartition par statut'), 'The status distribution section is missing.');
    ensureAdminStatistics(str_contains($html, 'Ouvert'), 'OPEN status label is missing.');
    ensureAdminStatistics(str_contains($html, 'En cours'), 'IN_PROGRESS status label is missing.');
    ensureAdminStatistics(str_contains($html, 'Résolu'), 'RESOLVED status label is missing.');
    ensureAdminStatistics(str_contains($html, 'Fermé'), 'CLOSED status label is missing.');
    ensureAdminStatistics(str_contains($html, 'Répartition par priorité'), 'The priority distribution section is missing.');
    ensureAdminStatistics(str_contains($html, 'Basse'), 'LOW priority label is missing.');
    ensureAdminStatistics(str_contains($html, 'Moyenne'), 'MEDIUM priority label is missing.');
    ensureAdminStatistics(str_contains($html, 'Haute'), 'HIGH priority label is missing.');
    ensureAdminStatistics(str_contains($html, 'Urgente'), 'URGENT priority label is missing.');
    ensureAdminStatistics(str_contains($html, 'Répartition par catégorie'), 'The category distribution section is missing.');
    ensureAdminStatistics(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category labels must be escaped.');
    ensureAdminStatistics(!str_contains($html, '<script>alert("category")</script>'), 'An unescaped category label was rendered.');
    ensureAdminStatistics(str_contains($html, 'Non catégorisé'), 'Uncategorized label is missing.');
    ensureAdminStatistics(!str_contains($html, 'Unused category must stay absent'), 'Unused categories must not be displayed.');
    ensureAdminStatistics(str_contains($html, 'Tickets créés sur les 7 derniers jours'), 'The timeline section is missing.');
    ensureAdminStatistics(7 === substr_count($html, '<div class="admin-statistics__bar-fill"'), 'The timeline must render exactly 7 days.');
    ensureAdminStatistics(str_contains($html, $today->format('d/m/Y')), 'Today must be included in the timeline.');
    ensureAdminStatistics(str_contains($html, $today->modify('-6 days')->format('d/m/Y')), 'J-6 must be included in the timeline.');
    ensureAdminStatistics(str_contains($html, '>0<'), 'Missing days must display 0.');
    ensureAdminStatistics(!str_contains($html, '<form'), 'The admin statistics page must not render any form.');
    ensureAdminStatistics(!str_contains($html, 'method="post"'), 'The admin statistics page must not render POST forms.');
    ensureAdminStatistics(!str_contains($html, 'Exporter'), 'The admin statistics page must not expose exports.');
    ensureAdminStatistics($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering admin statistics must not mutate tickets.');

    $oldestPosition = strpos($html, $today->modify('-6 days')->format('d/m/Y'));
    $todayPosition = strpos($html, $today->format('d/m/Y'));
    ensureAdminStatistics(
        false !== $oldestPosition
        && false !== $todayPosition
        && $oldestPosition < $todayPosition,
        'Timeline must be ordered from oldest day to most recent day.',
    );

    $emptyHtml = $controllerContainer->get('twig')->render('admin/statistics.html.twig', [
        'total_ticket_count' => 0,
        'status_counts' => [
            Ticket::STATUS_OPEN => 0,
            Ticket::STATUS_IN_PROGRESS => 0,
            Ticket::STATUS_RESOLVED => 0,
            Ticket::STATUS_CLOSED => 0,
        ],
        'priority_counts' => [
            Ticket::PRIORITY_LOW => 0,
            Ticket::PRIORITY_MEDIUM => 0,
            Ticket::PRIORITY_HIGH => 0,
            Ticket::PRIORITY_URGENT => 0,
        ],
        'category_counts' => [],
        'created_by_day' => array_map(
            static fn (int $offset): array => [
                'date' => (new DateTimeImmutable('today'))->modify(sprintf('-%d days', 6 - $offset)),
                'ticketCount' => 0,
            ],
            range(0, 6),
        ),
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
    ensureAdminStatistics(str_contains($emptyHtml, 'Aucun ticket catégorisé ou non catégorisé à afficher.'), 'The empty category statistics state is missing.');
    ensureAdminStatistics(str_contains($emptyHtml, 'Total des tickets'), 'The empty statistics page must still show the total section.');

    echo "Admin statistics tests: PASS\n";
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
