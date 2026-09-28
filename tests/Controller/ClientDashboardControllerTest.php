<?php

declare(strict_types=1);

use App\Controller\ClientController;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\TicketRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureClientDashboard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dashboardUser(string $email, string $firstname): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname($firstname)
        ->setLastname('Test')
        ->setRoles(['ROLE_CLIENT'])
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function dashboardTicket(User $client, string $title, string $status, DateTimeImmutable $createdAt): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description de test')
        ->setStatus($status)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client);
}

function authenticatedDashboardRequest(TokenStorageInterface $tokenStorage, User $user): Request
{
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    return Request::create('/client', 'GET');
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var ClientController $controller */
$controller = $container->get(ClientController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var Psr\Container\ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);

$connection->beginTransaction();
$testRequest = Request::create('/client', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $client = dashboardUser('dashboard-client@example.test', 'Marie');
    $otherClient = dashboardUser('dashboard-other@example.test', 'Autre');
    $emptyClient = dashboardUser('dashboard-empty@example.test', 'Camille');
    $entityManager->persist($client);
    $entityManager->persist($otherClient);
    $entityManager->persist($emptyClient);

    $sameCreationDate = new DateTimeImmutable('today 10:00:00');
    $oldTicket = dashboardTicket($client, 'Ancienne demande exclue', Ticket::STATUS_OPEN, new DateTimeImmutable('2025-01-01 10:00:00'));
    $firstRecentTicket = dashboardTicket($client, 'Première demande récente', Ticket::STATUS_IN_PROGRESS, $sameCreationDate);
    $secondRecentTicket = dashboardTicket($client, '<script>alert("dashboard")</script>', Ticket::STATUS_RESOLVED, $sameCreationDate);
    $closedTicket = dashboardTicket($client, 'Demande clôturée', Ticket::STATUS_CLOSED, new DateTimeImmutable('2025-01-02 10:00:00'));
    $foreignTicket = dashboardTicket($otherClient, 'Ticket confidentiel autre client', Ticket::STATUS_CLOSED, new DateTimeImmutable('tomorrow'));

    foreach ([$oldTicket, $firstRecentTicket, $secondRecentTicket, $closedTicket, $foreignTicket] as $ticket) {
        $entityManager->persist($ticket);
    }
    $entityManager->flush();

    $recentTickets = $ticketRepository->findRecentForClient($client);
    ensureClientDashboard(2 === count($recentTickets), 'The dashboard must return at most two tickets.');
    ensureClientDashboard($secondRecentTicket === $recentTickets[0], 'Equal creation dates must be ordered by descending id.');
    ensureClientDashboard($firstRecentTicket === $recentTickets[1], 'The second recent ticket order is incorrect.');
    ensureClientDashboard(!in_array($oldTicket, $recentTickets, true), 'An older ticket bypassed the result limit.');
    ensureClientDashboard(!in_array($foreignTicket, $recentTickets, true), 'A ticket owned by another client was exposed.');

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));

    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->index($ticketRepository);
    $html = $response->getContent();

    ensureClientDashboard(Response::HTTP_OK === $response->getStatusCode(), 'The client dashboard must render with HTTP 200.');
    ensureClientDashboard(str_contains($html, 'Bonjour Marie 👋'), 'The personalized greeting is missing.');
    ensureClientDashboard(str_contains($html, 'Suivez vos demandes de support.'), 'The dashboard subtitle is missing.');
    ensureClientDashboard(str_contains($html, 'Demandes récentes'), 'The recent requests heading is missing.');
    ensureClientDashboard(str_contains($html, 'Première demande récente'), 'A recent client ticket is missing.');
    ensureClientDashboard(str_contains($html, '&lt;script&gt;alert(&quot;dashboard&quot;)&lt;/script&gt;'), 'Ticket titles must be escaped.');
    ensureClientDashboard(!str_contains($html, '<script>alert("dashboard")</script>'), 'An unescaped ticket title was rendered.');
    ensureClientDashboard(!str_contains($html, 'Ancienne demande exclue'), 'More than two tickets were rendered.');
    ensureClientDashboard(!str_contains($html, 'Ticket confidentiel autre client'), 'Another client ticket was rendered.');
    ensureClientDashboard(str_contains($html, 'En cours'), 'The IN_PROGRESS label is missing.');
    ensureClientDashboard(str_contains($html, 'Résolu'), 'The RESOLVED label is missing.');
    ensureClientDashboard(str_contains($html, 'Créé aujourd’hui'), 'Today must use the expected relative label.');
    ensureClientDashboard(str_contains($html, '/client/tickets'), 'The ticket-list link is missing.');
    ensureClientDashboard(str_contains($html, '/client/ticket/new'), 'The new-ticket link is missing.');
    ensureClientDashboard(str_contains($html, '/client/tickets/'.$secondRecentTicket->getId()), 'The ticket-detail link is missing.');
    ensureClientDashboard($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the dashboard must not persist data.');

    $absoluteDateHtml = $twig->render('client/index.html.twig', [
        'client' => $client,
        'recent_tickets' => [$oldTicket],
        'status_labels' => [
            Ticket::STATUS_OPEN => 'Ouvert',
            Ticket::STATUS_IN_PROGRESS => 'En cours',
            Ticket::STATUS_RESOLVED => 'Résolu',
            Ticket::STATUS_CLOSED => 'Fermé',
        ],
    ]);
    ensureClientDashboard(str_contains($absoluteDateHtml, '01/01/2025'), 'Older tickets must use the d/m/Y format.');
    ensureClientDashboard(str_contains($absoluteDateHtml, 'Ouvert'), 'The OPEN label is missing.');
    $closedTicketHtml = $twig->render('client/index.html.twig', [
        'client' => $client,
        'recent_tickets' => [$closedTicket],
        'status_labels' => [
            Ticket::STATUS_OPEN => 'Ouvert',
            Ticket::STATUS_IN_PROGRESS => 'En cours',
            Ticket::STATUS_RESOLVED => 'Résolu',
            Ticket::STATUS_CLOSED => 'Fermé',
        ],
    ]);
    ensureClientDashboard(str_contains($closedTicketHtml, 'Demande clôturée'), 'The CLOSED ticket must be displayed.');
    ensureClientDashboard(str_contains($closedTicketHtml, 'Fermé'), 'The CLOSED status label is missing.');

    $httpResponse = $kernel->handle(authenticatedDashboardRequest($tokenStorage, $client), HttpKernelInterface::SUB_REQUEST);
    ensureClientDashboard(Response::HTTP_OK === $httpResponse->getStatusCode(), sprintf('Authenticated GET /client must return 200 (got %d: %s).', $httpResponse->getStatusCode(), $httpResponse->headers->get('Location')));
    ensureClientDashboard(str_contains($httpResponse->getContent(), 'Demandes récentes'), 'Authenticated GET /client must render the dashboard.');

    $tokenStorage->setToken(null);
    $anonymousResponse = $kernel->handle(Request::create('/client', 'GET'));
    ensureClientDashboard(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /client returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureClientDashboard(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /client must redirect to login.',
        );
    }

    $technician = dashboardUser('dashboard-technician@example.test', 'Technicien');
    $technician->setRoles(['ROLE_TECHNICIAN']);
    $entityManager->persist($technician);
    $entityManager->flush();
    $roleResponse = $kernel->handle(authenticatedDashboardRequest($tokenStorage, $technician), HttpKernelInterface::SUB_REQUEST);
    ensureClientDashboard(
        Response::HTTP_FORBIDDEN === $roleResponse->getStatusCode(),
        sprintf('A non-client role must be denied access to /client (got %d).', $roleResponse->getStatusCode()),
    );

    $tokenStorage->setToken(new UsernamePasswordToken($emptyClient, 'main', $emptyClient->getRoles()));
    $emptyResponse = $controller->index($ticketRepository);
    $emptyHtml = $emptyResponse->getContent();
    ensureClientDashboard(str_contains($emptyHtml, "Vous n&#039;avez encore aucune demande de support."), 'The empty state is missing.');
    ensureClientDashboard(str_contains($emptyHtml, 'Créer une nouvelle demande'), 'The empty-state action is missing.');

    echo "Client dashboard tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
