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
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureTechnicianDashboard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicianDashboardUser(
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

function technicianDashboardTicket(
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
        ->setDescription('Description masquée sur le dashboard')
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function technicianDashboardRequest(TokenStorageInterface $tokenStorage, ?User $user, string $path = '/technician'): Request
{
    if (null === $user) {
        $tokenStorage->setToken(null);
    } else {
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

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
$testRequest = Request::create('/technician', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $technician = technicianDashboardUser('technician-dashboard@example.test', ['ROLE_TECHNICIAN']);
    $otherTechnician = technicianDashboardUser('technician-dashboard-other@example.test', ['ROLE_TECHNICIAN'], 'Autre');
    $client = technicianDashboardUser(
        'technician-dashboard-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $clientUser = technicianDashboardUser('technician-dashboard-role-client@example.test', ['ROLE_CLIENT'], 'ClientRole');
    $userWithoutTechnicianRole = technicianDashboardUser('technician-dashboard-user@example.test', ['ROLE_USER'], 'UserOnly');
    $category = (new Category())->setName('<script>alert("category")</script>');

    foreach ([$technician, $otherTechnician, $client, $clientUser, $userWithoutTechnicianRole, $category] as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();

    $openTicketCountBefore = $ticketRepository->countOpenTickets();
    $assignedToMeCountBefore = $ticketRepository->countAssignedToTechnicianExcludingClosed($technician);
    $openUnassignedCountBefore = $ticketRepository->countOpenUnassignedTickets();

    $sameCreatedAt = new DateTimeImmutable('2099-09-10 10:00:00');
    $takeNewer = technicianDashboardTicket($client, '<script>alert("title")</script>', Ticket::STATUS_OPEN, $sameCreatedAt, null, $category, Ticket::PRIORITY_URGENT);
    $takeHigherId = technicianDashboardTicket($client, 'Ticket à prendre même date', Ticket::STATUS_OPEN, $sameCreatedAt, null, null, Ticket::PRIORITY_HIGH);
    $takeOld1 = technicianDashboardTicket($client, 'Ticket à prendre 1', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-09 10:00:00'), null, null, Ticket::PRIORITY_LOW);
    $takeOld2 = technicianDashboardTicket($client, 'Ticket à prendre 2', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-08 10:00:00'));
    $takeOld3 = technicianDashboardTicket($client, 'Ticket à prendre 3', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-07 10:00:00'));
    $takeExcludedByLimit = technicianDashboardTicket($client, 'Ticket à prendre exclu limite', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-06 10:00:00'));
    $resolvedUnassigned = technicianDashboardTicket($client, 'Résolu non assigné exclu', Ticket::STATUS_RESOLVED, new DateTimeImmutable('2099-09-14 10:00:00'));

    $assignedResolved = technicianDashboardTicket($client, 'Assigné résolu autorisé', Ticket::STATUS_RESOLVED, new DateTimeImmutable('2099-09-13 10:00:00'), $technician);
    $assignedOpen = technicianDashboardTicket($client, 'Assigné ouvert', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-12 10:00:00'), $technician);
    $assignedInProgress = technicianDashboardTicket($client, 'Assigné en cours', Ticket::STATUS_IN_PROGRESS, new DateTimeImmutable('2099-09-11 10:00:00'), $technician);
    $assignedOld1 = technicianDashboardTicket($client, 'Assigné ancien 1', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-10 09:00:00'), $technician);
    $assignedOld2 = technicianDashboardTicket($client, 'Assigné ancien 2', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-09 09:00:00'), $technician);
    $assignedExcludedByLimit = technicianDashboardTicket($client, 'Assigné exclu limite', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-08 09:00:00'), $technician);
    $assignedClosed = technicianDashboardTicket($client, 'Assigné fermé exclu', Ticket::STATUS_CLOSED, new DateTimeImmutable('2099-09-15 10:00:00'), $technician);
    $assignedOtherTechnician = technicianDashboardTicket($client, 'Assigné autre technicien exclu', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-09-16 10:00:00'), $otherTechnician);

    foreach ([
        $takeNewer,
        $takeHigherId,
        $takeOld1,
        $takeOld2,
        $takeOld3,
        $takeExcludedByLimit,
        $resolvedUnassigned,
        $assignedResolved,
        $assignedOpen,
        $assignedInProgress,
        $assignedOld1,
        $assignedOld2,
        $assignedExcludedByLimit,
        $assignedClosed,
        $assignedOtherTechnician,
    ] as $ticket) {
        $entityManager->persist($ticket);
    }

    $entityManager->flush();

    ensureTechnicianDashboard($openTicketCountBefore + 11 === $ticketRepository->countOpenTickets(), 'OPEN tickets must be counted exactly.');
    ensureTechnicianDashboard($assignedToMeCountBefore + 6 === $ticketRepository->countAssignedToTechnicianExcludingClosed($technician), 'Assigned-to-current-technician tickets excluding CLOSED must be counted exactly.');
    ensureTechnicianDashboard($openUnassignedCountBefore + 6 === $ticketRepository->countOpenUnassignedTickets(), 'Only OPEN unassigned tickets must be counted.');

    $ticketsToTake = $ticketRepository->findOpenUnassignedTickets();
    ensureTechnicianDashboard(5 === count($ticketsToTake), 'Tickets to take must be limited to 5.');
    ensureTechnicianDashboard([$takeHigherId, $takeNewer, $takeOld1, $takeOld2, $takeOld3] === $ticketsToTake, 'Tickets to take must be ordered by createdAt DESC then id DESC.');
    ensureTechnicianDashboard(!in_array($takeExcludedByLimit, $ticketsToTake, true), 'The sixth unassigned ticket must not be returned.');
    ensureTechnicianDashboard(!in_array($resolvedUnassigned, $ticketsToTake, true), 'A non-OPEN unassigned ticket was returned.');

    $assignedTickets = $ticketRepository->findAssignedToTechnicianExcludingClosed($technician);
    ensureTechnicianDashboard(5 === count($assignedTickets), 'Assigned tickets must be limited to 5.');
    ensureTechnicianDashboard([$assignedResolved, $assignedOpen, $assignedInProgress, $assignedOld1, $assignedOld2] === $assignedTickets, 'Assigned tickets must be ordered by createdAt DESC then id DESC.');
    ensureTechnicianDashboard(in_array($assignedResolved, $assignedTickets, true), 'A RESOLVED assigned ticket must remain visible.');
    ensureTechnicianDashboard(!in_array($assignedClosed, $assignedTickets, true), 'A CLOSED assigned ticket must be excluded.');
    ensureTechnicianDashboard(!in_array($assignedOtherTechnician, $assignedTickets, true), 'Another technician assigned ticket must be excluded.');

    $tokenStorage->setToken(new UsernamePasswordToken($technician, 'main', $technician->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $response = $controller->index($ticketRepository);
    $html = $response->getContent();

    ensureTechnicianDashboard(Response::HTTP_OK === $response->getStatusCode(), 'The technician dashboard must render with HTTP 200.');
    ensureTechnicianDashboard(str_contains($html, 'Dashboard technicien'), 'The dashboard heading is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Tickets ouverts'), 'The OPEN metric label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Assignés à moi'), 'The assigned metric label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Non assignés'), 'The unassigned metric label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Tickets à prendre'), 'The tickets-to-take section is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Mes tickets assignés'), 'The assigned tickets section is missing.');
    ensureTechnicianDashboard(str_contains($html, sprintf('technician-dashboard__metric-value">%d<', $openTicketCountBefore + 11)), 'The OPEN metric value is incorrect.');
    ensureTechnicianDashboard(str_contains($html, sprintf('technician-dashboard__metric-value">%d<', $assignedToMeCountBefore + 6)), 'The assigned metric value is incorrect.');
    ensureTechnicianDashboard(str_contains($html, sprintf('technician-dashboard__metric-value">%d<', $openUnassignedCountBefore + 6)), 'The unassigned metric value is incorrect.');
    ensureTechnicianDashboard(str_contains($html, 'Ticket à prendre même date'), 'An OPEN unassigned ticket is missing.');
    ensureTechnicianDashboard(str_contains($html, '&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;'), 'Ticket titles must be escaped.');
    ensureTechnicianDashboard(!str_contains($html, '<script>alert("title")</script>'), 'An unescaped ticket title was rendered.');
    ensureTechnicianDashboard(str_contains($html, 'Assigné résolu autorisé'), 'A RESOLVED assigned ticket must be rendered.');
    ensureTechnicianDashboard(str_contains($html, 'Assigné en cours'), 'An IN_PROGRESS assigned ticket must be rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'Ticket à prendre exclu limite'), 'More than five tickets to take were rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'Assigné exclu limite'), 'More than five assigned tickets were rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'Assigné fermé exclu'), 'A CLOSED assigned ticket was rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'Assigné autre technicien exclu'), 'Another technician assigned ticket was rendered.');
    ensureTechnicianDashboard(str_contains($html, 'Ouvert'), 'The OPEN status label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'En cours'), 'The IN_PROGRESS status label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Résolu'), 'The RESOLVED status label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Urgente'), 'The URGENT priority label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Haute'), 'The HIGH priority label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Basse'), 'The LOW priority label is missing.');
    ensureTechnicianDashboard(str_contains($html, 'Non catégorisé'), 'The uncategorized label is missing.');
    ensureTechnicianDashboard(str_contains($html, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category names must be escaped.');
    ensureTechnicianDashboard(str_contains($html, '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client'), 'Client identity must be displayed and escaped.');
    ensureTechnicianDashboard(str_contains($html, '10/09/2099 10:00'), 'Ticket dates must use d/m/Y H:i.');
    ensureTechnicianDashboard(str_contains($html, '/technician/tickets/'.$takeHigherId->getId()), 'The ticket detail link is missing.');
    ensureTechnicianDashboard(str_contains($html, '/technician/tickets'), 'The all-tickets link is missing.');
    ensureTechnicianDashboard(!str_contains($html, '<form'), 'The dashboard must not render any form.');
    ensureTechnicianDashboard(!str_contains($html, 'Modifier le statut'), 'The dashboard must not expose status mutation.');
    ensureTechnicianDashboard(!str_contains($html, 'Modifier la priorité'), 'The dashboard must not expose priority mutation.');
    ensureTechnicianDashboard(!str_contains($html, 'Modifier la catégorie'), 'The dashboard must not expose category mutation.');
    ensureTechnicianDashboard(!str_contains($html, 'Ajouter une intervention'), 'The dashboard must not expose intervention creation.');
    ensureTechnicianDashboard(!str_contains($html, 'Description masquée sur le dashboard'), 'The full description must not be rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'TicketHistory'), 'Ticket history must not be rendered.');
    ensureTechnicianDashboard(!str_contains($html, 'AIAnalysis'), 'AI analyses must not be rendered.');
    ensureTechnicianDashboard($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the dashboard must not persist tickets.');

    $sameDateHigherIdPosition = strpos($html, 'Ticket à prendre même date');
    $sameDateOlderIdPosition = strpos($html, '&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;');
    $assignedResolvedPosition = strpos($html, 'Assigné résolu autorisé');
    $assignedOpenPosition = strpos($html, 'Assigné ouvert');
    $assignedInProgressPosition = strpos($html, 'Assigné en cours');
    ensureTechnicianDashboard(
        false !== $sameDateHigherIdPosition
        && false !== $sameDateOlderIdPosition
        && $sameDateHigherIdPosition < $sameDateOlderIdPosition,
        'Tickets to take are not ordered by id DESC when createdAt is equal.',
    );
    ensureTechnicianDashboard(
        false !== $assignedResolvedPosition
        && false !== $assignedOpenPosition
        && false !== $assignedInProgressPosition
        && $assignedResolvedPosition < $assignedOpenPosition
        && $assignedOpenPosition < $assignedInProgressPosition,
        'Assigned tickets are not ordered by createdAt DESC.',
    );

    $httpResponse = $kernel->handle(technicianDashboardRequest($tokenStorage, $technician), HttpKernelInterface::SUB_REQUEST);
    ensureTechnicianDashboard(Response::HTTP_OK === $httpResponse->getStatusCode(), sprintf('Authenticated GET /technician must return 200 (got %d).', $httpResponse->getStatusCode()));

    $anonymousResponse = $kernel->handle(technicianDashboardRequest($tokenStorage, null), HttpKernelInterface::SUB_REQUEST);
    ensureTechnicianDashboard(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /technician returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureTechnicianDashboard(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /technician must redirect to login.',
        );
    }

    $clientRoleResponse = $kernel->handle(technicianDashboardRequest($tokenStorage, $clientUser), HttpKernelInterface::SUB_REQUEST);
    ensureTechnicianDashboard(Response::HTTP_FORBIDDEN === $clientRoleResponse->getStatusCode(), 'ROLE_CLIENT must be denied access to /technician.');

    $userRoleResponse = $kernel->handle(technicianDashboardRequest($tokenStorage, $userWithoutTechnicianRole), HttpKernelInterface::SUB_REQUEST);
    ensureTechnicianDashboard(Response::HTTP_FORBIDDEN === $userRoleResponse->getStatusCode(), 'A user without ROLE_TECHNICIAN must be denied access to /technician.');

    echo "Technician dashboard tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
