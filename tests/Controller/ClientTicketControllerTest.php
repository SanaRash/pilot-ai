<?php

declare(strict_types=1);

use App\Controller\ClientTicketController;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\InterventionRepository;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureClientTickets(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientTicketsUser(string $email, string $firstname, array $roles = ['ROLE_CLIENT']): User
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

function clientTicketsTicket(User $client, string $title, string $status, DateTimeImmutable $createdAt): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description masquee dans la liste')
        ->setStatus($status)
        ->setPriority(Ticket::PRIORITY_URGENT)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client);
}

function clientTicketsAuthenticatedRequest(TokenStorageInterface $tokenStorage, User $user, string $path): Request
{
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    return Request::create($path, 'GET');
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var ClientTicketController $controller */
$controller = $container->get(ClientTicketController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var InterventionRepository $interventionRepository */
$interventionRepository = $entityManager->getRepository(Intervention::class);

$connection->beginTransaction();
$testRequest = Request::create('/client/tickets', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $client = clientTicketsUser('client-tickets@example.test', 'Sana');
    $otherClient = clientTicketsUser('client-tickets-other@example.test', 'Autre');
    $emptyClient = clientTicketsUser('client-tickets-empty@example.test', 'Vide');
    $technician = clientTicketsUser('client-tickets-tech@example.test', 'Tech', ['ROLE_TECHNICIAN']);

    foreach ([$client, $otherClient, $emptyClient, $technician] as $user) {
        $entityManager->persist($user);
    }

    $sameCreatedAt = new DateTimeImmutable('today 09:00:00');
    $openTicket = clientTicketsTicket($client, 'Ticket ouvert', Ticket::STATUS_OPEN, new DateTimeImmutable('2025-01-01 10:00:00'));
    $inProgressTicket = clientTicketsTicket($client, '<script>alert("tickets")</script>', Ticket::STATUS_IN_PROGRESS, $sameCreatedAt);
    $resolvedTicket = clientTicketsTicket($client, 'Ticket resolu', Ticket::STATUS_RESOLVED, $sameCreatedAt);
    $closedTicket = clientTicketsTicket($client, 'Ticket ferme', Ticket::STATUS_CLOSED, new DateTimeImmutable('2025-01-02 10:00:00'));
    $foreignTicket = clientTicketsTicket($otherClient, 'Ticket confidentiel autre client', Ticket::STATUS_OPEN, new DateTimeImmutable('tomorrow'));

    foreach ([$openTicket, $inProgressTicket, $resolvedTicket, $closedTicket, $foreignTicket] as $ticket) {
        $entityManager->persist($ticket);
    }

    $entityManager->flush();

    $listedTickets = $ticketRepository->findAllForClient($client);
    ensureClientTickets(4 === count($listedTickets), 'The repository must return all current client tickets.');
    ensureClientTickets($resolvedTicket === $listedTickets[0], 'Equal creation dates must be ordered by descending id.');
    ensureClientTickets($inProgressTicket === $listedTickets[1], 'The secondary sort by id is incorrect.');
    ensureClientTickets(!in_array($foreignTicket, $listedTickets, true), 'A foreign client ticket was returned by the repository.');

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->tickets($ticketRepository);
    $html = $response->getContent();

    ensureClientTickets(Response::HTTP_OK === $response->getStatusCode(), 'The ticket list must render with HTTP 200.');
    ensureClientTickets(str_contains($html, 'Mes tickets'), 'The page title is missing.');
    ensureClientTickets(str_contains($html, 'Ticket ouvert'), 'The OPEN ticket is missing.');
    ensureClientTickets(str_contains($html, 'Ticket resolu'), 'The RESOLVED ticket is missing.');
    ensureClientTickets(str_contains($html, 'Ticket ferme'), 'The CLOSED ticket is missing.');
    ensureClientTickets(str_contains($html, '&lt;script&gt;alert(&quot;tickets&quot;)&lt;/script&gt;'), 'Ticket titles must be escaped.');
    ensureClientTickets(!str_contains($html, '<script>alert("tickets")</script>'), 'An unescaped title was rendered.');
    ensureClientTickets(!str_contains($html, 'Ticket confidentiel autre client'), 'Another client ticket was rendered.');
    ensureClientTickets(str_contains($html, 'Ouvert'), 'The OPEN status label is missing.');
    ensureClientTickets(str_contains($html, 'En cours'), 'The IN_PROGRESS status label is missing.');
    ensureClientTickets(str_contains($html, 'Résolu'), 'The RESOLVED status label is missing.');
    ensureClientTickets(str_contains($html, 'Fermé'), 'The CLOSED status label is missing.');
    ensureClientTickets(str_contains($html, 'Créé aujourd’hui'), 'Today must use the expected label.');
    ensureClientTickets(str_contains($html, '01/01/2025'), 'Older tickets must use the d/m/Y format.');
    ensureClientTickets(str_contains($html, '/client/ticket/new'), 'The new-ticket link is missing.');
    ensureClientTickets(str_contains($html, '/client/tickets/'.$resolvedTicket->getId()), 'The detail link is missing.');
    ensureClientTickets($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the list must not persist data.');

    $resolvedPosition = strpos($html, 'Ticket resolu');
    $inProgressPosition = strpos($html, '&lt;script&gt;alert(&quot;tickets&quot;)&lt;/script&gt;');
    $closedPosition = strpos($html, 'Ticket ferme');
    $openPosition = strpos($html, 'Ticket ouvert');
    ensureClientTickets(
        false !== $resolvedPosition
        && false !== $inProgressPosition
        && false !== $closedPosition
        && false !== $openPosition
        && $resolvedPosition < $inProgressPosition
        && $inProgressPosition < $closedPosition
        && $closedPosition < $openPosition,
        'Tickets are not rendered in createdAt DESC, id DESC order.',
    );

    foreach (['Description masquee dans la liste', Ticket::PRIORITY_URGENT, 'APP', 'Non categorise'] as $forbiddenContent) {
        ensureClientTickets(!str_contains($html, $forbiddenContent), sprintf('Forbidden content was rendered: %s', $forbiddenContent));
    }

    $httpResponse = $kernel->handle(clientTicketsAuthenticatedRequest($tokenStorage, $client, '/client/tickets'), HttpKernelInterface::SUB_REQUEST);
    ensureClientTickets(Response::HTTP_OK === $httpResponse->getStatusCode(), sprintf('Authenticated GET /client/tickets must return 200 (got %d).', $httpResponse->getStatusCode()));

    $tokenStorage->setToken(null);
    $anonymousResponse = $kernel->handle(Request::create('/client/tickets', 'GET'));
    ensureClientTickets(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /client/tickets returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureClientTickets(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /client/tickets must redirect to login.',
        );
    }

    $roleResponse = $kernel->handle(clientTicketsAuthenticatedRequest($tokenStorage, $technician, '/client/tickets'), HttpKernelInterface::SUB_REQUEST);
    ensureClientTickets(
        Response::HTTP_FORBIDDEN === $roleResponse->getStatusCode(),
        sprintf('A non-client role must be denied access to /client/tickets (got %d).', $roleResponse->getStatusCode()),
    );

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    try {
        $controller->show((int) $foreignTicket->getId(), $ticketRepository, $interventionRepository);
        throw new RuntimeException('A foreign client ticket detail was not rejected.');
    } catch (NotFoundHttpException) {
    }

    $emptyHtml = $twig->render('client_ticket/tickets.html.twig', [
        'tickets' => [],
        'status_labels' => [
            Ticket::STATUS_OPEN => 'Ouvert',
            Ticket::STATUS_IN_PROGRESS => 'En cours',
            Ticket::STATUS_RESOLVED => 'Résolu',
            Ticket::STATUS_CLOSED => 'Fermé',
        ],
    ]);
    ensureClientTickets(str_contains($emptyHtml, "Vous n&#039;avez encore aucune demande de support."), 'The empty state is missing.');
    ensureClientTickets(str_contains($emptyHtml, 'Créer une nouvelle demande'), 'The empty-state action is missing.');

    echo "Client ticket list tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
