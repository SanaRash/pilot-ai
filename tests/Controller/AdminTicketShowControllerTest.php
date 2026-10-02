<?php

declare(strict_types=1);

use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAdminTicketShow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminTicketShowUser(string $email, array $roles, string $firstname, string $lastname): User
{
    return (new User())
        ->setEmail($email)
        ->setRoles($roles)
        ->setPassword(password_hash('TestPassword123!', PASSWORD_DEFAULT))
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2099-12-01 09:00:00'));
}

function adminTicketShowLogin(Kernel $kernel, User $admin): Session
{
    $session = new Session(new MockArraySessionStorage());
    $loginRequest = Request::create('/login', 'GET');
    $loginRequest->setSession($session);
    $loginResponse = $kernel->handle($loginRequest, HttpKernelInterface::MAIN_REQUEST);
    ensureAdminTicketShow(Response::HTTP_OK === $loginResponse->getStatusCode(), 'GET /login must render before admin login.');
    preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', (string) $loginResponse->getContent(), $matches);
    ensureAdminTicketShow(isset($matches[1]) && '' !== $matches[1], 'Login CSRF token must be rendered.');

    $loginPost = Request::create('/login', 'POST', [
        '_username' => $admin->getEmail(),
        '_password' => 'TestPassword123!',
        '_csrf_token' => html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ], [], [], ['HTTP_ORIGIN' => 'http://localhost']);
    $loginPost->setSession($session);
    $loginPostResponse = $kernel->handle($loginPost, HttpKernelInterface::MAIN_REQUEST);
    ensureAdminTicketShow(Response::HTTP_FOUND === $loginPostResponse->getStatusCode(), 'Admin login must redirect after valid credentials.');
    ensureAdminTicketShow('/admin' === parse_url((string) $loginPostResponse->headers->get('Location'), PHP_URL_PATH), 'Admin login must redirect to the admin dashboard.');

    return $session;
}

function adminTicketShowResponse(Kernel $kernel, Session $session, string $path): Response
{
    $request = Request::create($path, 'GET');
    $request->setSession($session);
    $request->cookies->set($session->getName(), $session->getId());

    return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
}

function adminTicketShowCounts(Connection $connection): array
{
    return [
        'tickets' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'),
        'interventions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention'),
        'histories' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'),
        'analyses' => (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis'),
    ];
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$router = $container->get('router');

$connection->beginTransaction();

try {
    $admin = adminTicketShowUser('admin-ticket-show-admin@example.test', ['ROLE_ADMIN'], 'Admin', 'Lecture');
    $client = adminTicketShowUser(
        'admin-ticket-show-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $technician = adminTicketShowUser(
        'admin-ticket-show-technician@example.test',
        ['ROLE_TECHNICIAN'],
        '<script>alert("technician")</script>',
        'Technicien',
    );
    $category = (new Category())->setName('<script>alert("category")</script>');

    foreach ([$admin, $client, $technician, $category] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $createdAt = new DateTimeImmutable('2099-12-10 10:11:00');
    $updatedAt = new DateTimeImmutable('2099-12-11 12:13:00');
    $hostileText = '<script>alert("ticket")</script>';
    $ticket = (new Ticket())
        ->setTitle('Ticket '.$hostileText)
        ->setDescription('Description '.$hostileText)
        ->setStatus(Ticket::STATUS_IN_PROGRESS)
        ->setPriority(Ticket::PRIORITY_HIGH)
        ->setSource('EMAIL')
        ->setCreatedAt($createdAt)
        ->setUpdatedAt($updatedAt)
        ->setCreatedBy($client)
        ->setAssignedTo($technician)
        ->setCategory($category);
    $entityManager->persist($ticket);
    $unassignedTicket = (new Ticket())
        ->setTitle('Ticket sans assignation')
        ->setDescription('Ticket avec valeurs optionnelles absentes.')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2099-12-09 08:00:00'))
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setAssignedTo(null)
        ->setCategory(null);
    $entityManager->persist($unassignedTicket);
    $entityManager->flush();

    $intervention = (new Intervention())
        ->setTicket($ticket)
        ->setTechnician($technician)
        ->setContent('Intervention '.$hostileText)
        ->setCreatedAt(new DateTimeImmutable('2099-12-12 14:15:00'));
    $history = (new TicketHistory())
        ->setTicket($ticket)
        ->setChangedBy($technician)
        ->setAction('STATUS_CHANGED')
        ->setOldValue(Ticket::STATUS_OPEN)
        ->setNewValue(Ticket::STATUS_IN_PROGRESS)
        ->setCreatedAt(new DateTimeImmutable('2099-12-13 16:17:00'));
    $analysis = (new AIAnalysis())
        ->setTicket($ticket)
        ->setSummary('Résumé IA '.$hostileText)
        ->setSuggestedPriority(Ticket::PRIORITY_URGENT)
        ->setSuggestedCategory('<script>alert("suggested-category")</script>')
        ->setKeywords(['réseau', $hostileText])
        ->setSuggestions(['Suggestion '.$hostileText])
        ->setCreatedAt(new DateTimeImmutable('2099-12-14 18:19:00'));

    foreach ([$intervention, $history, $analysis] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $route = $router->getRouteCollection()->get('app_admin_ticket_show');
    ensureAdminTicketShow($route instanceof Route, 'The admin ticket detail route must exist.');
    ensureAdminTicketShow('/admin/tickets/{id}' === $route->getPath(), 'The admin ticket detail route path is unexpected.');
    ensureAdminTicketShow(['GET'] === $route->getMethods(), 'The admin ticket detail route must accept GET only.');
    ensureAdminTicketShow('\d+' === $route->getRequirement('id'), 'The admin ticket detail id must be an integer.');

    $session = adminTicketShowLogin($kernel, $admin);
    $beforeGet = adminTicketShowCounts($connection);
    $path = '/admin/tickets/'.$ticket->getId();
    $response = adminTicketShowResponse($kernel, $session, $path);
    ensureAdminTicketShow(Response::HTTP_OK === $response->getStatusCode(), sprintf('ROLE_ADMIN must receive HTTP 200 (got %d).', $response->getStatusCode()));
    $html = (string) $response->getContent();

    foreach ([
        '#'.$ticket->getId(),
        'Ticket &lt;script&gt;alert(&quot;ticket&quot;)&lt;/script&gt;',
        'Description &lt;script&gt;alert(&quot;ticket&quot;)&lt;/script&gt;',
        '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client',
        'En cours',
        'Haute',
        '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;',
        'EMAIL',
        '10/12/2099 10:11',
        '11/12/2099 12:13',
        '&lt;script&gt;alert(&quot;technician&quot;)&lt;/script&gt; Technicien',
        'Intervention &lt;script&gt;alert(&quot;ticket&quot;)&lt;/script&gt;',
        'STATUS_CHANGED',
        Ticket::STATUS_OPEN,
        'Résumé IA &lt;script&gt;alert(&quot;ticket&quot;)&lt;/script&gt;',
        'Urgente',
        '&lt;script&gt;alert(&quot;suggested-category&quot;)&lt;/script&gt;',
        'réseau',
        'Suggestion &lt;script&gt;alert(&quot;ticket&quot;)&lt;/script&gt;',
        'Consultation en lecture seule',
        'Retour au dashboard Admin',
    ] as $expected) {
        ensureAdminTicketShow(str_contains($html, $expected), sprintf('Admin ticket detail is missing expected content "%s".', $expected));
    }

    foreach ([
        $hostileText,
        '<script>alert("client")</script>',
        '<script>alert("technician")</script>',
        '<script>alert("category")</script>',
        '<script>alert("suggested-category")</script>',
    ] as $rawHostileValue) {
        ensureAdminTicketShow(!str_contains($html, $rawHostileValue), 'User-controlled content must be HTML-escaped.');
    }

    foreach ([
        '<form',
        'method="post"',
        'Modifier le statut',
        'Modifier la priorité',
        'Modifier la catégorie',
        "M'assigner ce ticket",
        'Ajouter une intervention',
        'Appliquer',
        'Accepter',
        'Générer',
    ] as $forbidden) {
        ensureAdminTicketShow(!str_contains($html, $forbidden), sprintf('Read-only page must not expose "%s".', $forbidden));
    }

    ensureAdminTicketShow($beforeGet === adminTicketShowCounts($connection), 'GET admin ticket detail must not mutate persisted data.');

    $unassignedResponse = adminTicketShowResponse($kernel, $session, '/admin/tickets/'.$unassignedTicket->getId());
    ensureAdminTicketShow(Response::HTTP_OK === $unassignedResponse->getStatusCode(), 'An unassigned ticket detail must return 200.');
    $unassignedHtml = (string) $unassignedResponse->getContent();
    foreach (['Non assigné', 'Non catégorisé', 'Non modifié'] as $fallback) {
        ensureAdminTicketShow(str_contains($unassignedHtml, $fallback), sprintf('Admin ticket detail must display fallback "%s".', $fallback));
    }
    ensureAdminTicketShow($beforeGet === adminTicketShowCounts($connection), 'GET admin ticket details must not mutate persisted data.');

    $missingResponse = adminTicketShowResponse($kernel, $session, '/admin/tickets/2147483000');
    ensureAdminTicketShow(Response::HTTP_NOT_FOUND === $missingResponse->getStatusCode(), sprintf(
        'An unknown ticket id must return 404 (got %d).',
        $missingResponse->getStatusCode(),
    ));

    echo "Admin ticket show controller tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
