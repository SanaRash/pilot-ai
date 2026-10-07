<?php

declare(strict_types=1);

use App\Controller\ClientTicketController;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\TicketRepository;
use App\Repository\InterventionRepository;
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

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureClientTicketShow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientTicketShowUser(string $email, array $roles = ['ROLE_CLIENT']): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname('Marie')
        ->setLastname('Test')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function clientTicketShowTicket(User $client, string $title, string $description, string $status, DateTimeImmutable $createdAt): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus($status)
        ->setPriority(Ticket::PRIORITY_URGENT)
        ->setSource('EMAIL')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client);
}

function clientTicketShowRequest(TokenStorageInterface $tokenStorage, ?User $user, string $path): Request
{
    $tokenStorage->setToken(null === $user ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()));

    $request = Request::create($path, 'GET');
    $request->setSession(new Session(new MockArraySessionStorage()));

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
/** @var ClientTicketController $controller */
$controller = $container->get(ClientTicketController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var InterventionRepository $interventionRepository */
$interventionRepository = $entityManager->getRepository(Intervention::class);

$connection->beginTransaction();
$testRequest = Request::create('/client/tickets/1', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $client = clientTicketShowUser('client-ticket-show@example.test');
    $otherClient = clientTicketShowUser('client-ticket-show-other@example.test');
    $technician = clientTicketShowUser('client-ticket-show-tech@example.test', ['ROLE_TECHNICIAN']);

    foreach ([$client, $otherClient, $technician] as $user) {
        $entityManager->persist($user);
    }

    $ticket = clientTicketShowTicket(
        $client,
        '<script>alert("title")</script>',
        "Description <script>alert(\"description\")</script>\nDeuxième ligne.",
        Ticket::STATUS_IN_PROGRESS,
        new DateTimeImmutable('2026-09-10 14:02:00'),
    );
    $foreignTicket = clientTicketShowTicket(
        $otherClient,
        'Ticket confidentiel autre client',
        'Description confidentielle autre client',
        Ticket::STATUS_OPEN,
        new DateTimeImmutable('2026-09-11 10:00:00'),
    );
    $ticketWithoutPublishedUpdates = clientTicketShowTicket(
        $client,
        'Ticket sans mise à jour publiée',
        'Demande en attente de suivi.',
        Ticket::STATUS_OPEN,
        new DateTimeImmutable('2026-09-12 10:00:00'),
    );

    $entityManager->persist($ticket);
    $entityManager->persist($foreignTicket);
    $entityManager->persist($ticketWithoutPublishedUpdates);
    $entityManager->flush();

    foreach ([
        (new Intervention())
            ->setTicket($ticket)
            ->setTechnician($technician)
            ->setContent('<script>alert("visible")</script>')
            ->setCreatedAt(new DateTimeImmutable('2026-09-12 09:00:00'))
            ->setIsClientVisible(true),
        (new Intervention())
            ->setTicket($ticket)
            ->setTechnician($technician)
            ->setContent('Mise à jour visible suivante')
            ->setCreatedAt(new DateTimeImmutable('2026-09-12 09:00:00'))
            ->setIsClientVisible(true),
        (new Intervention())
            ->setTicket($ticket)
            ->setTechnician($technician)
            ->setContent('Note interne confidentielle')
            ->setCreatedAt(new DateTimeImmutable('2026-09-13 09:00:00')),
        (new Intervention())
            ->setTicket($foreignTicket)
            ->setTechnician($technician)
            ->setContent('Mise à jour autre client')
            ->setCreatedAt(new DateTimeImmutable('2026-09-14 09:00:00'))
            ->setIsClientVisible(true),
    ] as $intervention) {
        $entityManager->persist($intervention);
    }
    $entityManager->flush();

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');
    $interventionCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention');
    $renderRequest = Request::create('/client/tickets/'.$ticket->getId(), 'GET');
    $renderRequest->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($renderRequest);
    try {
        $response = $controller->show((int) $ticket->getId(), $ticketRepository, $interventionRepository);
    } finally {
        $requestStack->pop();
    }
    $html = $response->getContent();

    ensureClientTicketShow(Response::HTTP_OK === $response->getStatusCode(), 'The owner must be able to view the ticket detail.');
    ensureClientTicketShow(str_contains($html, '#'.$ticket->getId()), 'The ticket id reference is missing.');
    ensureClientTicketShow(str_contains($html, '&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;'), 'The title must be escaped.');
    ensureClientTicketShow(!str_contains($html, '<script>alert("title")</script>'), 'An unescaped title was rendered.');
    ensureClientTicketShow(str_contains($html, 'Description &lt;script&gt;alert(&quot;description&quot;)&lt;/script&gt;'), 'The description must be escaped.');
    ensureClientTicketShow(!str_contains($html, '<script>alert("description")</script>'), 'An unescaped description was rendered.');
    ensureClientTicketShow(str_contains($html, 'En cours'), 'The translated status is missing.');
    ensureClientTicketShow(str_contains($html, '10/09/2026 14:02'), 'The d/m/Y H:i date is missing.');
    ensureClientTicketShow(str_contains($html, '← Mes tickets'), 'The back link label is missing.');
    ensureClientTicketShow(str_contains($html, '/client/tickets'), 'The back link must target the client ticket list.');
    ensureClientTicketShow(str_contains($html, 'Votre demande'), 'The request section is missing.');
    ensureClientTicketShow(str_contains($html, 'Suivi'), 'The tracking section is missing.');
    ensureClientTicketShow(str_contains($html, '10/09/2026 14:02 — Demande créée'), 'The derived creation tracking line is missing.');
    ensureClientTicketShow(!str_contains($html, 'Ticket confidentiel autre client'), 'Another client ticket was exposed.');
    ensureClientTicketShow(str_contains($html, 'Suivi de votre demande'), 'The client update section is missing.');
    ensureClientTicketShow(str_contains($html, '&lt;script&gt;alert(&quot;visible&quot;)&lt;/script&gt;'), 'Visible intervention content must be escaped.');
    ensureClientTicketShow(str_contains($html, 'Mise à jour visible suivante'), 'The second visible intervention is missing.');
    ensureClientTicketShow(
        strpos($html, '&lt;script&gt;alert(&quot;visible&quot;)&lt;/script&gt;') < strpos($html, 'Mise à jour visible suivante'),
        'Interventions with identical dates must be ordered by id.',
    );
    ensureClientTicketShow(!str_contains($html, '<script>alert("visible")</script>'), 'Raw intervention content must not be rendered.');
    ensureClientTicketShow(!str_contains($html, 'Note interne confidentielle'), 'Internal interventions must never be shown to the client.');
    ensureClientTicketShow(!str_contains($html, 'Mise à jour autre client'), 'A different client ticket intervention must not be shown.');
    ensureClientTicketShow(str_contains($html, '12/09/2026 09:00'), 'Visible intervention date is missing.');

    foreach ([
        Ticket::PRIORITY_URGENT,
        'EMAIL',
        'Catégorie',
        'Technicien',
        'TicketHistory',
        'Intervention',
        'AIAnalysis',
        'Modifier',
        'Priorité',
        'Source',
    ] as $forbiddenContent) {
        ensureClientTicketShow(!str_contains($html, $forbiddenContent), sprintf('Forbidden content was rendered: %s', $forbiddenContent));
    }
    ensureClientTicketShow(!str_contains($html, 'Conversation</h2>'), 'The ticket detail must not render the old conversation section.');
    ensureClientTicketShow(str_contains($html, 'data-assistant-form'), 'The independent assistant widget must remain available.');

    ensureClientTicketShow($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the detail must not create tickets.');
    ensureClientTicketShow($historyCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Rendering the detail must not create history.');
    ensureClientTicketShow($interventionCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention'), 'Rendering the detail must not mutate interventions.');

    $emptyRequest = Request::create('/client/tickets/'.$ticketWithoutPublishedUpdates->getId(), 'GET');
    $emptyRequest->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($emptyRequest);
    try {
        $emptyUpdatesResponse = $controller->show((int) $ticketWithoutPublishedUpdates->getId(), $ticketRepository, $interventionRepository);
    } finally {
        $requestStack->pop();
    }
    ensureClientTicketShow(str_contains((string) $emptyUpdatesResponse->getContent(), 'Aucune mise à jour n’a encore été publiée.'), 'The empty published-update state is missing.');

    try {
        $controller->show((int) $foreignTicket->getId(), $ticketRepository, $interventionRepository);
        throw new RuntimeException('A foreign client ticket detail was not rejected.');
    } catch (NotFoundHttpException) {
    }

    try {
        $controller->show(999999999, $ticketRepository, $interventionRepository);
        throw new RuntimeException('A missing ticket detail was not rejected.');
    } catch (NotFoundHttpException) {
    }

    $httpResponse = $kernel->handle(clientTicketShowRequest($tokenStorage, $client, '/client/tickets/'.$ticket->getId()), HttpKernelInterface::SUB_REQUEST);
    ensureClientTicketShow(Response::HTTP_OK === $httpResponse->getStatusCode(), sprintf('Authenticated GET detail must return 200 (got %d).', $httpResponse->getStatusCode()));

    $anonymousResponse = $kernel->handle(clientTicketShowRequest($tokenStorage, null, '/client/tickets/'.$ticket->getId()));
    ensureClientTicketShow(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET detail returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureClientTicketShow(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET detail must redirect to login.',
        );
    }

    $roleResponse = $kernel->handle(clientTicketShowRequest($tokenStorage, $technician, '/client/tickets/'.$ticket->getId()), HttpKernelInterface::SUB_REQUEST);
    ensureClientTicketShow(
        Response::HTTP_FORBIDDEN === $roleResponse->getStatusCode(),
        sprintf('A non-client role must be denied access to the client ticket detail (got %d).', $roleResponse->getStatusCode()),
    );

    echo "Client ticket detail tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $requestStack->pop();

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
