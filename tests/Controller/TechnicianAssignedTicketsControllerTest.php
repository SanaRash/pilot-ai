<?php

declare(strict_types=1);

use App\Controller\TechnicianController;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureTechnicianAssignedTickets(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicianAssignedTicketsUser(
    string $email,
    array $roles,
    string $firstname = 'Tech',
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

function technicianAssignedTicketsTicket(
    User $client,
    string $title,
    string $status,
    DateTimeImmutable $createdAt,
    ?User $assignedTo = null,
    ?Category $category = null,
    string $priority = Ticket::PRIORITY_MEDIUM,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description masquée dans les tickets assignés')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function technicianAssignedTicketsRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
{
    $session = new Session(new MockArraySessionStorage());
    $session->start();

    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $session->set('_security_main', serialize($token));
    }

    $request = Request::create('/technician/tickets/assigned', 'GET');
    $request->setSession($session);

    return $request;
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
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);

$connection->beginTransaction();
$testRequest = Request::create('/technician/tickets/assigned', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $technician = technicianAssignedTicketsUser('assigned-tickets-tech@example.test', ['ROLE_TECHNICIAN']);
    $otherTechnician = technicianAssignedTicketsUser('assigned-tickets-other-tech@example.test', ['ROLE_TECHNICIAN'], 'Autre', 'Technicien');
    $client = technicianAssignedTicketsUser(
        'assigned-tickets-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $roleClient = technicianAssignedTicketsUser('assigned-tickets-role-client@example.test', ['ROLE_CLIENT'], 'RoleClient');
    $category = (new Category())->setName('<script>alert("category")</script>');

    foreach ([$technician, $otherTechnician, $client, $roleClient, $category] as $entity) {
        $entityManager->persist($entity);
    }

    $sameCreatedAt = new DateTimeImmutable('2099-11-10 10:00:00');
    $assignedOpenLowerId = technicianAssignedTicketsTicket($client, '<script>alert("assigned")</script>', Ticket::STATUS_OPEN, $sameCreatedAt, $technician, $category, Ticket::PRIORITY_URGENT);
    $assignedOpenHigherId = technicianAssignedTicketsTicket($client, 'Assigné même date id plus haut', Ticket::STATUS_OPEN, $sameCreatedAt, $technician, null, Ticket::PRIORITY_HIGH);
    $assignedInProgress = technicianAssignedTicketsTicket($client, 'Assigné en cours visible', Ticket::STATUS_IN_PROGRESS, new DateTimeImmutable('2099-11-09 10:00:00'), $technician, null, Ticket::PRIORITY_LOW);
    $assignedResolved = technicianAssignedTicketsTicket($client, 'Assigné résolu visible', Ticket::STATUS_RESOLVED, new DateTimeImmutable('2099-11-08 10:00:00'), $technician);
    $assignedClosed = technicianAssignedTicketsTicket($client, 'Assigné fermé absent', Ticket::STATUS_CLOSED, new DateTimeImmutable('2099-11-11 10:00:00'), $technician);
    $assignedOtherTechnician = technicianAssignedTicketsTicket($client, 'Assigné autre technicien absent', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-11-12 10:00:00'), $otherTechnician);
    $unassignedTicket = technicianAssignedTicketsTicket($client, 'Ticket non assigné absent', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-11-13 10:00:00'));

    foreach ([
        $assignedOpenLowerId,
        $assignedOpenHigherId,
        $assignedInProgress,
        $assignedResolved,
        $assignedClosed,
        $assignedOtherTechnician,
        $unassignedTicket,
    ] as $ticket) {
        $entityManager->persist($ticket);
    }

    $entityManager->flush();

    $assignedTickets = $ticketRepository->findAllAssignedToTechnicianExcludingClosed($technician);
    ensureTechnicianAssignedTickets(in_array($assignedOpenLowerId, $assignedTickets, true), 'An OPEN assigned ticket must be returned.');
    ensureTechnicianAssignedTickets(in_array($assignedInProgress, $assignedTickets, true), 'An IN_PROGRESS assigned ticket must be returned.');
    ensureTechnicianAssignedTickets(in_array($assignedResolved, $assignedTickets, true), 'A RESOLVED assigned ticket must be returned.');
    ensureTechnicianAssignedTickets(!in_array($assignedClosed, $assignedTickets, true), 'A CLOSED assigned ticket was returned.');
    ensureTechnicianAssignedTickets(!in_array($assignedOtherTechnician, $assignedTickets, true), 'A ticket assigned to another technician was returned.');
    ensureTechnicianAssignedTickets(!in_array($unassignedTicket, $assignedTickets, true), 'An unassigned ticket was returned.');
    ensureTechnicianAssignedTickets(
        array_search($assignedOpenHigherId, $assignedTickets, true) < array_search($assignedOpenLowerId, $assignedTickets, true),
        'Assigned tickets with equal createdAt must be ordered by id DESC.',
    );

    $anonymousResponse = $kernel->handle(technicianAssignedTicketsRequest($tokenStorage, null));
    ensureTechnicianAssignedTickets(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /technician/tickets/assigned returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureTechnicianAssignedTickets(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /technician/tickets/assigned must redirect to login.',
        );
    }

    $clientRoleResponse = $kernel->handle(technicianAssignedTicketsRequest($tokenStorage, $roleClient));
    ensureTechnicianAssignedTickets(
        in_array($clientRoleResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('ROLE_CLIENT must be denied access to /technician/tickets/assigned (got %d).', $clientRoleResponse->getStatusCode()),
    );

    $tokenStorage->setToken(new UsernamePasswordToken($technician, 'main', $technician->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->assignedTickets($ticketRepository);
    $html = $response->getContent();

    ensureTechnicianAssignedTickets(Response::HTTP_OK === $response->getStatusCode(), 'The assigned-ticket list must render with HTTP 200.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Tickets assignés'), 'The page heading is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Assigné même date id plus haut'), 'An OPEN assigned ticket is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Assigné en cours visible'), 'An IN_PROGRESS assigned ticket is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Assigné résolu visible'), 'A RESOLVED assigned ticket is missing.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Assigné fermé absent'), 'A CLOSED assigned ticket was rendered.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Assigné autre technicien absent'), 'A ticket assigned to another technician was rendered.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Ticket non assigné absent'), 'An unassigned ticket was rendered.');
    ensureTechnicianAssignedTickets(str_contains($html, '&lt;script&gt;alert(&quot;assigned&quot;)&lt;/script&gt;'), 'Ticket titles must be escaped.');
    ensureTechnicianAssignedTickets(!str_contains($html, '<script>alert("assigned")</script>'), 'An unescaped ticket title was rendered.');
    ensureTechnicianAssignedTickets(str_contains($html, '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client'), 'Client identity must be displayed and escaped.');
    ensureTechnicianAssignedTickets(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category names must be escaped.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Ouvert'), 'The OPEN status label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'En cours'), 'The IN_PROGRESS status label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Résolu'), 'The RESOLVED status label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Urgente'), 'The URGENT priority label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Haute'), 'The HIGH priority label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Basse'), 'The LOW priority label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Moyenne'), 'The MEDIUM priority label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, 'Non catégorisé'), 'The uncategorized label is missing.');
    ensureTechnicianAssignedTickets(str_contains($html, '10/11/2099 10:00'), 'Ticket dates must use d/m/Y H:i.');
    ensureTechnicianAssignedTickets(str_contains($html, '/technician/tickets/'.$assignedOpenHigherId->getId()), 'The ticket detail link is missing.');
    ensureTechnicianAssignedTickets(!str_contains($html, '<form'), 'The assigned-ticket list must not render any form.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'M\'assigner'), 'The assigned-ticket list must not expose assignment.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Modifier le statut'), 'The assigned-ticket list must not expose status mutation.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Modifier la priorité'), 'The assigned-ticket list must not expose priority mutation.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Modifier la catégorie'), 'The assigned-ticket list must not expose category mutation.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Ajouter une intervention'), 'The assigned-ticket list must not expose intervention creation.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'Description masquée dans les tickets assignés'), 'The full description must not be rendered.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'APP'), 'The source must not be rendered.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'TicketHistory'), 'Ticket history must not be rendered.');
    ensureTechnicianAssignedTickets(!str_contains($html, 'AIAnalysis'), 'AI analyses must not be rendered.');
    ensureTechnicianAssignedTickets($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering assigned tickets must not persist tickets.');

    $higherIdPosition = strpos($html, 'Assigné même date id plus haut');
    $lowerIdPosition = strpos($html, '&lt;script&gt;alert(&quot;assigned&quot;)&lt;/script&gt;');
    $inProgressPosition = strpos($html, 'Assigné en cours visible');
    $resolvedPosition = strpos($html, 'Assigné résolu visible');
    ensureTechnicianAssignedTickets(
        false !== $higherIdPosition
        && false !== $lowerIdPosition
        && false !== $inProgressPosition
        && false !== $resolvedPosition
        && $higherIdPosition < $lowerIdPosition
        && $lowerIdPosition < $inProgressPosition
        && $inProgressPosition < $resolvedPosition,
        'Tickets are not rendered in createdAt DESC then id DESC order.',
    );

    $emptyHtml = $controllerContainer->get('twig')->render('technician/assigned_tickets.html.twig', [
        'tickets' => [],
        'status_labels' => [
            Ticket::STATUS_OPEN => 'Ouvert',
            Ticket::STATUS_IN_PROGRESS => 'En cours',
            Ticket::STATUS_RESOLVED => 'Résolu',
        ],
        'priority_labels' => [
            Ticket::PRIORITY_LOW => 'Basse',
            Ticket::PRIORITY_MEDIUM => 'Moyenne',
            Ticket::PRIORITY_HIGH => 'Haute',
            Ticket::PRIORITY_URGENT => 'Urgente',
        ],
    ]);
    ensureTechnicianAssignedTickets(str_contains($emptyHtml, 'Aucun ticket ne vous est assigné pour le moment.'), 'The empty state is missing.');

    echo "Technician assigned tickets tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
