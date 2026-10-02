<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\ClientController;
use App\Controller\ClientTicketController;
use App\Controller\TechnicianController;
use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use App\Repository\AIAnalysisRepository;
use App\Repository\CategoryRepository;
use App\Repository\InterventionRepository;
use App\Repository\TicketHistoryRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use App\Service\TicketHistoryService;
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

function ensureUiSmoke(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uiSmokeUser(string $email, array $roles, string $firstname, string $lastname): User
{
    return (new User())
        ->setEmail($email)
        ->setRoles($roles)
        ->setPassword(password_hash('CorrectHorseBatteryStaple123!', PASSWORD_DEFAULT))
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-09-01 09:00:00'));
}

function uiSmokeTicket(
    User $client,
    string $title,
    string $description,
    string $status,
    DateTimeImmutable $createdAt,
    ?Category $category = null,
    ?User $assignedTo = null,
    string $priority = Ticket::PRIORITY_MEDIUM,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setCategory($category)
        ->setAssignedTo($assignedTo);
}

function uiSmokePublicRequest(Kernel $kernel, string $path): Response
{
    $request = Request::create($path, 'GET');
    $request->setSession(new Session(new MockArraySessionStorage()));

    return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
}

function uiSmokeRender(
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
    User $user,
    string $path,
    callable $render,
    ?Session $session = null,
): Response {
    $session ??= new Session(new MockArraySessionStorage());
    $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
    $tokenStorage->setToken($token);
    $session->set('_security_main', serialize($token));

    $request = Request::create($path, 'GET');
    $request->attributes->set('_route', match ($path) {
        '/client' => 'app_client',
        '/client/tickets' => 'app_client_tickets',
        '/client/ticket/new' => 'app_client_ticket_new',
        '/technician' => 'app_technician',
        '/technician/tickets' => 'app_technician_tickets',
        '/technician/tickets/assigned' => 'app_technician_assigned_tickets',
        '/admin' => 'app_admin',
        '/admin/users' => 'app_admin_users',
        '/admin/categories' => 'app_admin_categories',
        '/admin/statistics' => 'app_admin_statistics',
        default => preg_match('#^/admin/tickets/\d+$#D', $path) ? 'app_admin_ticket_show' : null,
    });
    $request->setSession($session);
    $requestStack->push($request);

    try {
        return $render($request);
    } finally {
        $requestStack->pop();
    }
}

function uiSmokeAssertOk(Response $response, string $path): string
{
    ensureUiSmoke(
        Response::HTTP_OK === $response->getStatusCode(),
        sprintf('GET %s must return 200, got %d.', $path, $response->getStatusCode()),
    );

    $html = $response->getContent();
    ensureUiSmoke(is_string($html), sprintf('GET %s must return HTML content.', $path));
    ensureUiSmoke(str_contains($html, '<main'), sprintf('GET %s must render a main landmark.', $path));
    uiSmokeAssertNoSymfonyError($html, $path);

    return $html;
}

function uiSmokeAssertNoSymfonyError(string $html, string $path): void
{
    foreach (['An exception occurred', 'Stack trace', 'Symfony Exception', '500 Internal Server Error'] as $forbidden) {
        ensureUiSmoke(!str_contains($html, $forbidden), sprintf('GET %s rendered Symfony error marker "%s".', $path, $forbidden));
    }
}

function uiSmokeAssertNavigation(string $html, string $space, string $activeHref): void
{
    ensureUiSmoke(str_contains($html, 'aria-label="Navigation principale"'), sprintf('%s navigation landmark is missing.', $space));
    ensureUiSmoke(str_contains($html, 'Déconnexion'), sprintf('%s logout link is missing.', $space));
    ensureUiSmoke(str_contains($html, sprintf('href="%s"', $activeHref)), sprintf('%s active href is missing.', $space));
    ensureUiSmoke(str_contains($html, sprintf('href="%s" aria-current="page"', $activeHref)), sprintf('%s active link must expose aria-current=page.', $space));
}

function uiSmokeAssertOnlyClientNavigation(string $html): void
{
    ensureUiSmoke(str_contains($html, 'Espace client'), 'Client navigation group is missing.');
    ensureUiSmoke(!str_contains($html, 'Espace technicien'), 'Technician navigation must not be visible to client-only users.');
    ensureUiSmoke(!str_contains($html, 'Espace admin'), 'Admin navigation must not be visible to client-only users.');
}

function uiSmokeAssertOnlyTechnicianNavigation(string $html): void
{
    ensureUiSmoke(str_contains($html, 'Espace technicien'), 'Technician navigation group is missing.');
    ensureUiSmoke(!str_contains($html, 'Espace client'), 'Client navigation must not be visible to technician-only users.');
    ensureUiSmoke(!str_contains($html, 'Espace admin'), 'Admin navigation must not be visible to technician-only users.');
}

function uiSmokeAssertOnlyAdminNavigation(string $html): void
{
    ensureUiSmoke(str_contains($html, 'Espace admin'), 'Admin navigation group is missing.');
    ensureUiSmoke(!str_contains($html, 'Espace client'), 'Client navigation must not be visible to admin-only users.');
    ensureUiSmoke(!str_contains($html, 'Espace technicien'), 'Technician navigation must not be visible to admin-only users.');
}

function uiSmokeCounts(Connection $connection): array
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

function uiSmokeTemplate(string $path): string
{
    $content = file_get_contents(dirname(__DIR__, 2).'/'.$path);
    ensureUiSmoke(is_string($content), sprintf('Template %s must be readable.', $path));

    return $content;
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$entityManager = $container->get('doctrine')->getManager();
ensureUiSmoke($entityManager instanceof EntityManagerInterface, 'Doctrine did not provide an ORM entity manager.');
$connection = $entityManager->getConnection();

$clientController = $container->get(ClientController::class);
$clientTicketController = $container->get(ClientTicketController::class);
$technicianController = $container->get(TechnicianController::class);
$adminController = $container->get(AdminController::class);
ensureUiSmoke($clientController instanceof ClientController, 'ClientController service is unavailable.');
ensureUiSmoke($clientTicketController instanceof ClientTicketController, 'ClientTicketController service is unavailable.');
ensureUiSmoke($technicianController instanceof TechnicianController, 'TechnicianController service is unavailable.');
ensureUiSmoke($adminController instanceof AdminController, 'AdminController service is unavailable.');

$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
$controllerContainer = $controllerContainerProperty->getValue($clientController);
ensureUiSmoke($controllerContainer instanceof ContainerInterface, 'Controller container is unavailable.');
$tokenStorage = $controllerContainer->get('security.token_storage');
$requestStack = $controllerContainer->get('request_stack');
ensureUiSmoke($tokenStorage instanceof TokenStorageInterface, 'Token storage is unavailable.');
ensureUiSmoke($requestStack instanceof RequestStack, 'Request stack is unavailable.');

$ticketRepository = $entityManager->getRepository(Ticket::class);
$userRepository = $entityManager->getRepository(User::class);
$categoryRepository = $entityManager->getRepository(Category::class);
$interventionRepository = $entityManager->getRepository(Intervention::class);
$ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
$aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);
ensureUiSmoke($ticketRepository instanceof TicketRepository, 'Ticket repository is unavailable.');
ensureUiSmoke($userRepository instanceof UserRepository, 'User repository is unavailable.');
ensureUiSmoke($categoryRepository instanceof CategoryRepository, 'Category repository is unavailable.');
ensureUiSmoke($interventionRepository instanceof InterventionRepository, 'Intervention repository is unavailable.');
ensureUiSmoke($ticketHistoryRepository instanceof TicketHistoryRepository, 'Ticket history repository is unavailable.');
ensureUiSmoke($aiAnalysisRepository instanceof AIAnalysisRepository, 'AI analysis repository is unavailable.');
$ticketHistoryService = new TicketHistoryService($entityManager);

$connection->beginTransaction();

try {
    $hostile = '<script>alert("ui-smoke")</script>';
    $escapedHostile = '&lt;script&gt;alert(&quot;ui-smoke&quot;)&lt;/script&gt;';

    $client = uiSmokeUser('ui-smoke-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], $hostile, 'Client');
    $emptyClient = uiSmokeUser('ui-smoke-empty-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'Vide');
    $technician = uiSmokeUser('ui-smoke-technician-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Smoke');
    $admin = uiSmokeUser('ui-smoke-admin-'.$hostile.'-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_ADMIN'], 'Admin', 'Smoke');
    $category = (new Category())->setName($hostile);
    $otherCategory = (new Category())->setName('Catégorie smoke secondaire');

    foreach ([$client, $emptyClient, $technician, $admin, $category, $otherCategory] as $entity) {
        $entityManager->persist($entity);
    }

    $clientTicket = uiSmokeTicket($client, $hostile, 'Description '.$hostile, Ticket::STATUS_OPEN, new DateTimeImmutable('2099-08-01 10:00:00'), $category);
    $openUnassignedTicket = uiSmokeTicket($client, 'Ticket ouvert smoke', 'Description ticket ouvert smoke', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-08-02 10:00:00'), $otherCategory, null, Ticket::PRIORITY_HIGH);
    $assignedTicket = uiSmokeTicket($client, 'Ticket assigné smoke', 'Description assignée smoke', Ticket::STATUS_IN_PROGRESS, new DateTimeImmutable('2099-08-03 10:00:00'), $category, $technician, Ticket::PRIORITY_URGENT);
    $similarTicket = uiSmokeTicket($client, 'Ticket similaire smoke', 'Description laseruismoke proche', Ticket::STATUS_OPEN, new DateTimeImmutable('2099-08-04 10:00:00'), $category);

    foreach ([$clientTicket, $openUnassignedTicket, $assignedTicket, $similarTicket] as $ticket) {
        $entityManager->persist($ticket);
    }

    $intervention = (new Intervention())
        ->setTicket($assignedTicket)
        ->setTechnician($technician)
        ->setContent('Intervention '.$hostile)
        ->setCreatedAt(new DateTimeImmutable('2099-08-05 10:00:00'));
    $analysis = (new AIAnalysis())
        ->setTicket($assignedTicket)
        ->setSummary('Résumé IA smoke')
        ->setSuggestedPriority(Ticket::PRIORITY_HIGH)
        ->setSuggestedCategory('Suggestion '.$hostile)
        ->setKeywords(['laseruismoke', 'smoke'])
        ->setSuggestions(['Suggestion IA '.$hostile])
        ->setCreatedAt(new DateTimeImmutable('2099-08-06 10:00:00'));
    $history = (new TicketHistory())
        ->setTicket($assignedTicket)
        ->setAction('STATUS_CHANGED')
        ->setOldValue(Ticket::STATUS_OPEN)
        ->setNewValue(Ticket::STATUS_IN_PROGRESS)
        ->setChangedBy($technician)
        ->setCreatedAt(new DateTimeImmutable('2099-08-07 10:00:00'));

    foreach ([$intervention, $analysis, $history] as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();

    $countsBeforeSmoke = uiSmokeCounts($connection);

    $loginHtml = uiSmokeAssertOk(uiSmokePublicRequest($kernel, '/login'), '/login');
    ensureUiSmoke(str_contains($loginHtml, 'auth-page'), 'Login auth-page wrapper is missing.');
    ensureUiSmoke(str_contains($loginHtml, 'auth-card'), 'Login auth-card wrapper is missing.');
    ensureUiSmoke(str_contains($loginHtml, '<form class="auth-form" method="post">'), 'Login POST form is missing.');
    ensureUiSmoke(str_contains($loginHtml, 'name="_username"'), 'Login username field is missing.');
    ensureUiSmoke(str_contains($loginHtml, 'name="_password"'), 'Login password field is missing.');
    ensureUiSmoke(str_contains($loginHtml, 'name="_csrf_token"'), 'Login CSRF token is missing.');
    ensureUiSmoke(!str_contains($loginHtml, 'Navigation principale'), 'Anonymous login page must not show role navigation.');

    $registerHtml = uiSmokeAssertOk(uiSmokePublicRequest($kernel, '/register'), '/register');
    ensureUiSmoke(str_contains($registerHtml, 'auth-page'), 'Register auth-page wrapper is missing.');
    ensureUiSmoke(str_contains($registerHtml, 'auth-card'), 'Register auth-card wrapper is missing.');
    ensureUiSmoke(str_contains($registerHtml, '<form') && str_contains($registerHtml, 'class="auth-form"'), 'Register Symfony auth form is missing.');
    foreach (['registration_form[firstname]', 'registration_form[lastname]', 'registration_form[email]', 'registration_form[plainPassword]'] as $fieldName) {
        ensureUiSmoke(str_contains($registerHtml, sprintf('name="%s"', $fieldName)), sprintf('Register field %s is missing.', $fieldName));
    }
    ensureUiSmoke(!str_contains($registerHtml, 'Navigation principale'), 'Anonymous register page must not show role navigation.');

    $clientDashboardHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $client, '/client', static fn (): Response => $clientController->index($ticketRepository)), '/client');
    uiSmokeAssertNavigation($clientDashboardHtml, 'Client dashboard', '/client');
    uiSmokeAssertOnlyClientNavigation($clientDashboardHtml);
    ensureUiSmoke(str_contains($clientDashboardHtml, 'Bonjour'), 'Client dashboard greeting is missing.');
    ensureUiSmoke(str_contains($clientDashboardHtml, 'Demandes récentes'), 'Client dashboard recent requests section is missing.');
    ensureUiSmoke(str_contains($clientDashboardHtml, 'Voir tous mes tickets'), 'Client dashboard ticket-list action is missing.');
    ensureUiSmoke(str_contains($clientDashboardHtml, 'Nouvelle demande'), 'Client dashboard new-ticket action is missing.');
    ensureUiSmoke(str_contains($clientDashboardHtml, $escapedHostile), 'Client dashboard must escape hostile client/ticket data.');
    ensureUiSmoke(!str_contains($clientDashboardHtml, $hostile), 'Client dashboard rendered raw hostile data.');

    $clientTicketsHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $client, '/client/tickets', static fn (): Response => $clientTicketController->tickets($ticketRepository)), '/client/tickets');
    uiSmokeAssertNavigation($clientTicketsHtml, 'Client tickets', '/client/tickets');
    uiSmokeAssertOnlyClientNavigation($clientTicketsHtml);
    ensureUiSmoke(str_contains($clientTicketsHtml, 'Mes tickets'), 'Client tickets title is missing.');
    ensureUiSmoke(str_contains($clientTicketsHtml, '#'.$clientTicket->getId()), 'Client ticket card is missing.');
    ensureUiSmoke(str_contains($clientTicketsHtml, $escapedHostile), 'Client tickets page must escape hostile ticket title.');
    ensureUiSmoke(!str_contains($clientTicketsHtml, $hostile), 'Client tickets page rendered raw hostile data.');

    $clientNewHtml = uiSmokeAssertOk(uiSmokeRender(
        $requestStack,
        $tokenStorage,
        $client,
        '/client/ticket/new',
        static fn (Request $request): Response => $clientTicketController->new($request, $entityManager, $ticketHistoryService),
    ), '/client/ticket/new');
    uiSmokeAssertNavigation($clientNewHtml, 'Client new ticket', '/client/ticket/new');
    uiSmokeAssertOnlyClientNavigation($clientNewHtml);
    ensureUiSmoke(str_contains($clientNewHtml, 'Nouvelle demande'), 'Client new-ticket title is missing.');
    ensureUiSmoke(str_contains($clientNewHtml, 'name="ticket[title]"'), 'Client new-ticket title field is missing.');
    ensureUiSmoke(str_contains($clientNewHtml, 'name="ticket[description]"'), 'Client new-ticket description field is missing.');
    ensureUiSmoke(str_contains($clientNewHtml, 'name="ticket[_token]"'), 'Client new-ticket CSRF field is missing.');

    $clientShowHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $client, '/client/tickets/'.$clientTicket->getId(), static fn (): Response => $clientTicketController->show((int) $clientTicket->getId(), $ticketRepository)), '/client/tickets/{id}');
    ensureUiSmoke(str_contains($clientShowHtml, 'Votre demande'), 'Client ticket detail section is missing.');
    ensureUiSmoke(str_contains($clientShowHtml, 'Suivi'), 'Client ticket tracking section is missing.');
    ensureUiSmoke(str_contains($clientShowHtml, 'Ouvert'), 'Client ticket status is missing.');
    ensureUiSmoke(str_contains($clientShowHtml, $escapedHostile), 'Client ticket detail must escape hostile title/description.');
    ensureUiSmoke(!str_contains($clientShowHtml, $hostile), 'Client ticket detail rendered raw hostile data.');

    $emptyClientTicketsHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $emptyClient, '/client/tickets', static fn (): Response => $clientTicketController->tickets($ticketRepository)), '/client/tickets empty');
    ensureUiSmoke(str_contains($emptyClientTicketsHtml, 'class="empty-state"'), 'Client empty state component is missing.');
    ensureUiSmoke(str_contains($emptyClientTicketsHtml, 'Créer une nouvelle demande'), 'Client empty state allowed action is missing.');

    $flashSession = new Session(new MockArraySessionStorage());
    $flashSession->getFlashBag()->add('success', 'Succès '.$hostile);
    $flashHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $client, '/client/tickets', static fn (): Response => $clientTicketController->tickets($ticketRepository), $flashSession), '/client/tickets with flash');
    ensureUiSmoke(1 === substr_count($flashHtml, 'class="flash-messages"'), 'Flash container must be rendered exactly once.');
    ensureUiSmoke(str_contains($flashHtml, 'flash-messages__message--success'), 'Success flash class is missing.');
    ensureUiSmoke(str_contains($flashHtml, 'role="status"'), 'Success flash ARIA role is missing.');
    ensureUiSmoke(str_contains($flashHtml, 'Succès '.$escapedHostile), 'Flash message must be escaped.');
    ensureUiSmoke(!str_contains($flashHtml, 'Succès '.$hostile), 'Flash message rendered raw hostile data.');

    $technicianDashboardHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $technician, '/technician', static fn (): Response => $technicianController->index($ticketRepository)), '/technician');
    uiSmokeAssertNavigation($technicianDashboardHtml, 'Technician dashboard', '/technician');
    uiSmokeAssertOnlyTechnicianNavigation($technicianDashboardHtml);
    ensureUiSmoke(str_contains($technicianDashboardHtml, 'Tickets ouverts'), 'Technician dashboard open tickets label is missing.');
    ensureUiSmoke(str_contains($technicianDashboardHtml, 'Tickets à prendre'), 'Technician dashboard tickets-to-take section is missing.');

    $technicianOpenHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $technician, '/technician/tickets', static fn (): Response => $technicianController->tickets($ticketRepository)), '/technician/tickets');
    uiSmokeAssertNavigation($technicianOpenHtml, 'Technician open tickets', '/technician/tickets');
    uiSmokeAssertOnlyTechnicianNavigation($technicianOpenHtml);
    ensureUiSmoke(str_contains($technicianOpenHtml, 'Ticket ouvert smoke'), 'Technician open tickets list is missing the open ticket.');
    ensureUiSmoke(str_contains($technicianOpenHtml, $escapedHostile), 'Technician open tickets must escape hostile category/client data.');
    ensureUiSmoke(!str_contains($technicianOpenHtml, $hostile), 'Technician open tickets rendered raw hostile data.');

    $technicianAssignedHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $technician, '/technician/tickets/assigned', static fn (): Response => $technicianController->assignedTickets($ticketRepository)), '/technician/tickets/assigned');
    uiSmokeAssertNavigation($technicianAssignedHtml, 'Technician assigned tickets', '/technician/tickets/assigned');
    uiSmokeAssertOnlyTechnicianNavigation($technicianAssignedHtml);
    ensureUiSmoke(str_contains($technicianAssignedHtml, 'Ticket assigné smoke'), 'Technician assigned ticket is missing.');

    $technicianShowHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $technician, '/technician/tickets/'.$assignedTicket->getId(), static fn (): Response => $technicianController->show($assignedTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository)), '/technician/tickets/{id}');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Ticket assigné smoke'), 'Technician detail ticket title is missing.');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Résumé IA smoke'), 'Technician detail AI analysis is missing.');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Suggestion IA '.$escapedHostile), 'Technician detail AI suggestion must be escaped.');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Ticket similaire smoke'), 'Technician detail similar ticket is missing.');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Intervention '.$escapedHostile), 'Technician detail intervention must be escaped.');
    ensureUiSmoke(str_contains($technicianShowHtml, 'Statut modifié'), 'Technician detail history is missing.');
    ensureUiSmoke(!str_contains($technicianShowHtml, $hostile), 'Technician detail rendered raw hostile data.');

    $adminDashboardHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $admin, '/admin', static fn (): Response => $adminController->index($ticketRepository, $userRepository, $categoryRepository)), '/admin');
    uiSmokeAssertNavigation($adminDashboardHtml, 'Admin dashboard', '/admin');
    uiSmokeAssertOnlyAdminNavigation($adminDashboardHtml);
    ensureUiSmoke(str_contains($adminDashboardHtml, 'Dashboard admin'), 'Admin dashboard title is missing.');
    ensureUiSmoke(str_contains($adminDashboardHtml, 'Tickets récents'), 'Admin dashboard recent tickets section is missing.');
    ensureUiSmoke(!str_contains($adminDashboardHtml, '<form'), 'Admin dashboard must remain read-only.');

    $adminTicketShowPath = '/admin/tickets/'.$assignedTicket->getId();
    $adminTicketShowHtml = uiSmokeAssertOk(uiSmokeRender(
        $requestStack,
        $tokenStorage,
        $admin,
        $adminTicketShowPath,
        static fn (): Response => $adminController->showTicket(
            $assignedTicket,
            $interventionRepository,
            $ticketHistoryRepository,
            $aiAnalysisRepository,
        ),
    ), $adminTicketShowPath);
    uiSmokeAssertOnlyAdminNavigation($adminTicketShowHtml);
    ensureUiSmoke(str_contains($adminTicketShowHtml, 'href="/admin">Dashboard</a>'), 'Admin ticket detail must retain the Admin dashboard navigation.');
    ensureUiSmoke(str_contains($adminTicketShowHtml, 'Consultation en lecture seule'), 'Admin ticket detail read-only notice is missing.');
    ensureUiSmoke(str_contains($adminTicketShowHtml, 'Ticket assigné smoke'), 'Admin ticket detail title is missing.');
    ensureUiSmoke(str_contains($adminTicketShowHtml, 'Résumé IA smoke'), 'Admin ticket detail AI analysis is missing.');
    ensureUiSmoke(str_contains($adminTicketShowHtml, 'Intervention '.$escapedHostile), 'Admin ticket detail intervention is missing.');
    ensureUiSmoke(!str_contains($adminTicketShowHtml, $hostile), 'Admin ticket detail rendered raw hostile data.');
    ensureUiSmoke(!str_contains($adminTicketShowHtml, '<form'), 'Admin ticket detail must remain read-only.');

    $adminUsersHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $admin, '/admin/users', static fn (): Response => $adminController->users($userRepository)), '/admin/users');
    uiSmokeAssertNavigation($adminUsersHtml, 'Admin users', '/admin/users');
    uiSmokeAssertOnlyAdminNavigation($adminUsersHtml);
    ensureUiSmoke(str_contains($adminUsersHtml, 'Liste des utilisateurs'), 'Admin users table is missing.');
    ensureUiSmoke(str_contains($adminUsersHtml, $escapedHostile), 'Admin users must escape hostile user data.');
    ensureUiSmoke(!str_contains($adminUsersHtml, $hostile), 'Admin users rendered raw hostile data.');
    ensureUiSmoke(!str_contains($adminUsersHtml, '<form'), 'Admin users must remain read-only.');

    $adminCategoriesHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $admin, '/admin/categories', static fn (): Response => $adminController->categories($categoryRepository)), '/admin/categories');
    uiSmokeAssertNavigation($adminCategoriesHtml, 'Admin categories', '/admin/categories');
    uiSmokeAssertOnlyAdminNavigation($adminCategoriesHtml);
    ensureUiSmoke(str_contains($adminCategoriesHtml, 'Catégories'), 'Admin categories title is missing.');
    ensureUiSmoke(str_contains($adminCategoriesHtml, $escapedHostile), 'Admin categories must escape hostile category data.');
    ensureUiSmoke(!str_contains($adminCategoriesHtml, $hostile), 'Admin categories rendered raw hostile data.');
    ensureUiSmoke(!str_contains($adminCategoriesHtml, '<form'), 'Admin categories must remain read-only.');

    $adminStatisticsHtml = uiSmokeAssertOk(uiSmokeRender($requestStack, $tokenStorage, $admin, '/admin/statistics', static fn (): Response => $adminController->statistics($ticketRepository)), '/admin/statistics');
    uiSmokeAssertNavigation($adminStatisticsHtml, 'Admin statistics', '/admin/statistics');
    uiSmokeAssertOnlyAdminNavigation($adminStatisticsHtml);
    ensureUiSmoke(str_contains($adminStatisticsHtml, 'Statistiques'), 'Admin statistics title is missing.');
    ensureUiSmoke(str_contains($adminStatisticsHtml, 'Volume total'), 'Admin statistics volume section is missing.');
    ensureUiSmoke(str_contains($adminStatisticsHtml, 'Répartition par catégorie'), 'Admin statistics category section is missing.');
    ensureUiSmoke(str_contains($adminStatisticsHtml, $escapedHostile), 'Admin statistics must escape hostile category labels.');
    ensureUiSmoke(!str_contains($adminStatisticsHtml, $hostile), 'Admin statistics rendered raw hostile data.');
    ensureUiSmoke(!str_contains($adminStatisticsHtml, '<form'), 'Admin statistics must remain read-only.');

    $baseTemplate = uiSmokeTemplate('templates/base.html.twig');
    ensureUiSmoke(str_contains($baseTemplate, 'box-sizing: border-box;'), 'Global responsive box-sizing rule is missing.');
    ensureUiSmoke(str_contains($baseTemplate, 'overflow-wrap: anywhere;'), 'Global long-content overflow rule is missing.');
    ensureUiSmoke(str_contains($baseTemplate, '@media (max-width: 48rem)'), 'Base mobile media query is missing.');
    ensureUiSmoke(!str_contains(strtolower(uiSmokeTemplate('templates/_role_navigation.html.twig')), 'burger'), 'Role navigation must not introduce a burger menu.');
    foreach (['templates/admin/users.html.twig', 'templates/admin/categories.html.twig'] as $adminTemplatePath) {
        $adminTemplate = uiSmokeTemplate($adminTemplatePath);
        ensureUiSmoke(str_contains($adminTemplate, 'overflow-x: auto;'), sprintf('%s must keep horizontal table overflow.', $adminTemplatePath));
        ensureUiSmoke(str_contains($adminTemplate, '-webkit-overflow-scrolling: touch;'), sprintf('%s must keep touch scrolling.', $adminTemplatePath));
    }

    ensureUiSmoke($countsBeforeSmoke === uiSmokeCounts($connection), 'GET smoke rendering must not mutate persisted data.');

    echo "UI smoke tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
