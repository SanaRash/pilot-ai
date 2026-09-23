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

function ensureTechnicianOpenTickets(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicianOpenTicketsUser(
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

function technicianOpenTicketsTicket(
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
        ->setDescription('Description masquée dans les tickets ouverts')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function technicianOpenTicketsRequest(TokenStorageInterface $tokenStorage, ?User $user): Request
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

    $request = Request::create('/technician/tickets', 'GET');
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
$testRequest = Request::create('/technician/tickets', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $technician = technicianOpenTicketsUser('open-tickets-tech@example.test', ['ROLE_TECHNICIAN']);
    $otherTechnician = technicianOpenTicketsUser('open-tickets-other-tech@example.test', ['ROLE_TECHNICIAN'], 'Autre', 'Technicien');
    $client = technicianOpenTicketsUser(
        'open-tickets-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $roleClient = technicianOpenTicketsUser('open-tickets-role-client@example.test', ['ROLE_CLIENT'], 'RoleClient');
    $category = (new Category())->setName('<script>alert("category")</script>');

    foreach ([$technician, $otherTechnician, $client, $roleClient, $category] as $entity) {
        $entityManager->persist($entity);
    }

    $sameCreatedAt = new DateTimeImmutable('2099-10-10 10:00:00');
    $openUnassignedLowerId = technicianOpenTicketsTicket($client, '<script>alert("open")</script>', Ticket::STATUS_OPEN, $sameCreatedAt, null, $category, Ticket::PRIORITY_URGENT);
    $openUnassignedHigherId = technicianOpenTicketsTicket($client, 'Open même date id plus haut', Ticket::STATUS_OPEN, $sameCreatedAt, null, null, Ticket::PRIORITY_HIGH);
    $openAssignedToMe = technicianOpenTicketsTicket($client, 'Open assigné à moi', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-10-09 10:00:00'), $technician, null, Ticket::PRIORITY_LOW);
    $openAssignedOther = technicianOpenTicketsTicket($client, 'Open assigné autre technicien', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-10-08 10:00:00'), $otherTechnician);
    $inProgressTicket = technicianOpenTicketsTicket($client, 'Ticket en cours absent', Ticket::STATUS_IN_PROGRESS, new DateTimeImmutable('2099-10-11 10:00:00'), $technician);
    $resolvedTicket = technicianOpenTicketsTicket($client, 'Ticket résolu absent', Ticket::STATUS_RESOLVED, new DateTimeImmutable('2099-10-12 10:00:00'));
    $closedTicket = technicianOpenTicketsTicket($client, 'Ticket fermé absent', Ticket::STATUS_CLOSED, new DateTimeImmutable('2099-10-13 10:00:00'));

    foreach ([
        $openUnassignedLowerId,
        $openUnassignedHigherId,
        $openAssignedToMe,
        $openAssignedOther,
        $inProgressTicket,
        $resolvedTicket,
        $closedTicket,
    ] as $ticket) {
        $entityManager->persist($ticket);
    }

    $entityManager->flush();

    $openTickets = $ticketRepository->findOpenTickets();
    ensureTechnicianOpenTickets(in_array($openUnassignedLowerId, $openTickets, true), 'An OPEN unassigned ticket must be returned.');
    ensureTechnicianOpenTickets(in_array($openAssignedToMe, $openTickets, true), 'An OPEN ticket assigned to the current technician must be returned.');
    ensureTechnicianOpenTickets(in_array($openAssignedOther, $openTickets, true), 'An OPEN ticket assigned to another technician must be returned.');
    ensureTechnicianOpenTickets(!in_array($inProgressTicket, $openTickets, true), 'An IN_PROGRESS ticket was returned.');
    ensureTechnicianOpenTickets(!in_array($resolvedTicket, $openTickets, true), 'A RESOLVED ticket was returned.');
    ensureTechnicianOpenTickets(!in_array($closedTicket, $openTickets, true), 'A CLOSED ticket was returned.');
    ensureTechnicianOpenTickets(
        array_search($openUnassignedHigherId, $openTickets, true) < array_search($openUnassignedLowerId, $openTickets, true),
        'OPEN tickets with equal createdAt must be ordered by id DESC.',
    );

    $anonymousResponse = $kernel->handle(technicianOpenTicketsRequest($tokenStorage, null));
    ensureTechnicianOpenTickets(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /technician/tickets returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureTechnicianOpenTickets(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /technician/tickets must redirect to login.',
        );
    }

    $clientRoleResponse = $kernel->handle(technicianOpenTicketsRequest($tokenStorage, $roleClient));
    ensureTechnicianOpenTickets(
        in_array($clientRoleResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('ROLE_CLIENT must be denied access to /technician/tickets (got %d).', $clientRoleResponse->getStatusCode()),
    );

    $tokenStorage->setToken(new UsernamePasswordToken($technician, 'main', $technician->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->tickets($ticketRepository);
    $html = $response->getContent();

    ensureTechnicianOpenTickets(Response::HTTP_OK === $response->getStatusCode(), 'The open-ticket list must render with HTTP 200.');
    ensureTechnicianOpenTickets(str_contains($html, 'Tickets ouverts'), 'The page heading is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Open même date id plus haut'), 'An OPEN unassigned ticket is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Open assigné à moi'), 'An OPEN ticket assigned to the current technician is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Open assigné autre technicien'), 'An OPEN ticket assigned to another technician is missing.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Ticket en cours absent'), 'An IN_PROGRESS ticket was rendered.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Ticket résolu absent'), 'A RESOLVED ticket was rendered.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Ticket fermé absent'), 'A CLOSED ticket was rendered.');
    ensureTechnicianOpenTickets(str_contains($html, '&lt;script&gt;alert(&quot;open&quot;)&lt;/script&gt;'), 'Ticket titles must be escaped.');
    ensureTechnicianOpenTickets(!str_contains($html, '<script>alert("open")</script>'), 'An unescaped ticket title was rendered.');
    ensureTechnicianOpenTickets(str_contains($html, '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client'), 'Client identity must be displayed and escaped.');
    ensureTechnicianOpenTickets(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category names must be escaped.');
    ensureTechnicianOpenTickets(str_contains($html, 'Ouvert'), 'The OPEN status label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Urgente'), 'The URGENT priority label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Haute'), 'The HIGH priority label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Basse'), 'The LOW priority label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Moyenne'), 'The MEDIUM priority label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Non catégorisé'), 'The uncategorized label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Non assigné'), 'The unassigned label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Assigné à Tech Test'), 'The current technician assignment label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, 'Assigné à Autre Technicien'), 'The other technician assignment label is missing.');
    ensureTechnicianOpenTickets(str_contains($html, '10/10/2099 10:00'), 'Ticket dates must use d/m/Y H:i.');
    ensureTechnicianOpenTickets(str_contains($html, '/technician/tickets/'.$openUnassignedHigherId->getId()), 'The ticket detail link is missing.');
    ensureTechnicianOpenTickets(!str_contains($html, '<form'), 'The open-ticket list must not render any form.');
    ensureTechnicianOpenTickets(!str_contains($html, 'M\'assigner'), 'The open-ticket list must not expose assignment.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Modifier le statut'), 'The open-ticket list must not expose status mutation.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Modifier la priorité'), 'The open-ticket list must not expose priority mutation.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Modifier la catégorie'), 'The open-ticket list must not expose category mutation.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Ajouter une intervention'), 'The open-ticket list must not expose intervention creation.');
    ensureTechnicianOpenTickets(!str_contains($html, 'Description masquée dans les tickets ouverts'), 'The full description must not be rendered.');
    ensureTechnicianOpenTickets(!str_contains($html, 'APP'), 'The source must not be rendered.');
    ensureTechnicianOpenTickets(!str_contains($html, 'TicketHistory'), 'Ticket history must not be rendered.');
    ensureTechnicianOpenTickets(!str_contains($html, 'AIAnalysis'), 'AI analyses must not be rendered.');
    ensureTechnicianOpenTickets($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering open tickets must not persist tickets.');

    $higherIdPosition = strpos($html, 'Open même date id plus haut');
    $lowerIdPosition = strpos($html, '&lt;script&gt;alert(&quot;open&quot;)&lt;/script&gt;');
    $assignedToMePosition = strpos($html, 'Open assigné à moi');
    $assignedOtherPosition = strpos($html, 'Open assigné autre technicien');
    ensureTechnicianOpenTickets(
        false !== $higherIdPosition
        && false !== $lowerIdPosition
        && false !== $assignedToMePosition
        && false !== $assignedOtherPosition
        && $higherIdPosition < $lowerIdPosition
        && $lowerIdPosition < $assignedToMePosition
        && $assignedToMePosition < $assignedOtherPosition,
        'Tickets are not rendered in createdAt DESC then id DESC order.',
    );

    $emptyHtml = $controllerContainer->get('twig')->render('technician/tickets.html.twig', [
        'tickets' => [],
        'status_labels' => [Ticket::STATUS_OPEN => 'Ouvert'],
        'priority_labels' => [
            Ticket::PRIORITY_LOW => 'Basse',
            Ticket::PRIORITY_MEDIUM => 'Moyenne',
            Ticket::PRIORITY_HIGH => 'Haute',
            Ticket::PRIORITY_URGENT => 'Urgente',
        ],
    ]);
    ensureTechnicianOpenTickets(str_contains($emptyHtml, 'Aucun ticket ouvert pour le moment.'), 'The empty state is missing.');

    echo "Technician open tickets tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
