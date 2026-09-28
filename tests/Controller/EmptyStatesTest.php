<?php

declare(strict_types=1);

use App\Controller\ClientController;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureEmptyStates(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function emptyStatesUser(array $roles = ['ROLE_CLIENT']): User
{
    return (new User())
        ->setEmail('empty-states-'.sha1(implode('-', $roles)).'@example.test')
        ->setFirstname('Empty')
        ->setLastname('States')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function renderEmptyStateTemplate(
    Environment $twig,
    RequestStack $requestStack,
    TokenStorageInterface $tokenStorage,
    string $template,
    array $context,
    array $roles = ['ROLE_CLIENT'],
    string $path = '/client',
    string $routeName = 'app_client',
): string {
    $user = emptyStatesUser($roles);
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    $request = Request::create($path, 'GET');
    $request->attributes->set('_route', $routeName);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($request);

    try {
        return $twig->render($template, $context);
    } finally {
        $requestStack->pop();
    }
}

function ensureContainsEmptyState(string $html, string $message): void
{
    ensureEmptyStates(str_contains($html, 'class="empty-state"'), 'The common empty-state component is missing.');
    ensureEmptyStates(str_contains($html, $message), sprintf('Missing empty-state message: %s', $message));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var ClientController $controller */
$controller = $container->get(ClientController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var Environment $twig */
$twig = $controllerContainer->get('twig');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');

try {
    $partialMessageHtml = $twig->render('_empty_state.html.twig', [
        'message' => 'Message <script>alert("empty")</script>',
    ]);
    ensureEmptyStates(str_contains($partialMessageHtml, 'Message &lt;script&gt;alert(&quot;empty&quot;)&lt;/script&gt;'), 'Partial message must be escaped.');
    ensureEmptyStates(!str_contains($partialMessageHtml, '<script>alert("empty")</script>'), 'Partial must not render raw HTML.');
    ensureEmptyStates(!str_contains($partialMessageHtml, 'empty-state__action'), 'Partial must not render an action without action parameters.');

    $partialActionHtml = $twig->render('_empty_state.html.twig', [
        'message' => 'Avec action',
        'action_label' => 'Créer <script>alert("action")</script>',
        'action_path' => '/client/ticket/new',
    ]);
    ensureEmptyStates(str_contains($partialActionHtml, 'href="/client/ticket/new"'), 'Partial action link is missing.');
    ensureEmptyStates(str_contains($partialActionHtml, 'Créer &lt;script&gt;alert(&quot;action&quot;)&lt;/script&gt;'), 'Partial action label must be escaped.');

    $partialInvalidActionHtml = $twig->render('_empty_state.html.twig', [
        'message' => 'Action incomplète',
        'action_label' => 'Action seule',
    ]);
    ensureEmptyStates(!str_contains($partialInvalidActionHtml, 'empty-state__action'), 'Partial must not render an invalid partial action.');

    $statusLabels = [
        Ticket::STATUS_OPEN => 'Ouvert',
        Ticket::STATUS_IN_PROGRESS => 'En cours',
        Ticket::STATUS_RESOLVED => 'Résolu',
        Ticket::STATUS_CLOSED => 'Fermé',
    ];
    $priorityLabels = [
        Ticket::PRIORITY_LOW => 'Basse',
        Ticket::PRIORITY_MEDIUM => 'Moyenne',
        Ticket::PRIORITY_HIGH => 'Haute',
        Ticket::PRIORITY_URGENT => 'Urgente',
    ];

    $clientDashboardHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'client/index.html.twig', [
        'client' => emptyStatesUser(),
        'recent_tickets' => [],
        'status_labels' => $statusLabels,
    ]);
    ensureContainsEmptyState($clientDashboardHtml, 'aucune demande de support');
    ensureEmptyStates(str_contains($clientDashboardHtml, 'Créer une nouvelle demande'), 'Client dashboard empty state action is missing.');
    ensureEmptyStates(str_contains($clientDashboardHtml, 'href="/client/ticket/new"'), 'Client dashboard empty action must target app_client_ticket_new.');

    $clientTicketsHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'client_ticket/tickets.html.twig', [
        'tickets' => [],
        'status_labels' => $statusLabels,
    ], ['ROLE_CLIENT'], '/client/tickets', 'app_client_tickets');
    ensureContainsEmptyState($clientTicketsHtml, 'aucune demande de support');
    ensureEmptyStates(str_contains($clientTicketsHtml, 'href="/client/ticket/new"'), 'Client ticket list empty action must target app_client_ticket_new.');

    $technicianDashboardHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'technician/index.html.twig', [
        'open_ticket_count' => 0,
        'assigned_to_me_count' => 0,
        'open_unassigned_count' => 0,
        'tickets_to_take' => [],
        'assigned_tickets' => [],
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
    ], ['ROLE_TECHNICIAN'], '/technician', 'app_technician');
    ensureContainsEmptyState($technicianDashboardHtml, 'Aucun ticket ouvert non assigné pour le moment.');
    ensureEmptyStates(str_contains($technicianDashboardHtml, 'Aucun ticket actif ne vous est assigné pour le moment.'), 'Technician dashboard assigned empty state is missing.');
    ensureEmptyStates(!str_contains($technicianDashboardHtml, 'Créer une nouvelle demande'), 'Technician dashboard must not expose a client action.');

    $technicianOpenHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'technician/tickets.html.twig', [
        'tickets' => [],
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
    ], ['ROLE_TECHNICIAN'], '/technician/tickets', 'app_technician_tickets');
    ensureContainsEmptyState($technicianOpenHtml, 'Aucun ticket ouvert pour le moment.');

    $technicianAssignedHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'technician/assigned_tickets.html.twig', [
        'tickets' => [],
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
    ], ['ROLE_TECHNICIAN'], '/technician/tickets/assigned', 'app_technician_assigned_tickets');
    ensureContainsEmptyState($technicianAssignedHtml, 'Aucun ticket actif ne vous est assigné pour le moment.');

    $ticket = (new Ticket())
        ->setTitle('Ticket état vide')
        ->setDescription('Description')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 10:00:00'))
        ->setCreatedBy(emptyStatesUser())
        ->setAssignedTo(emptyStatesUser(['ROLE_TECHNICIAN']));
    $ticketIdProperty = new ReflectionProperty(Ticket::class, 'id');
    $ticketIdProperty->setValue($ticket, 1);

    $technicianShowHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'technician/ticket_show.html.twig', [
        'ticket' => $ticket,
        'categories' => [],
        'allowed_statuses' => Ticket::ALLOWED_STATUSES,
        'allowed_priorities' => Ticket::ALLOWED_PRIORITIES,
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
        'can_update_status' => false,
        'can_update_priority' => false,
        'can_update_category' => false,
        'can_add_intervention' => false,
        'intervention_form' => null,
        'interventions' => [],
        'ticket_history' => [],
        'ai_analyses' => [],
        'similar_tickets' => [],
        'history_action_labels' => [],
    ], ['ROLE_TECHNICIAN'], '/technician/tickets/1', 'app_technician_ticket_show');
    foreach ([
        'Aucune analyse IA disponible pour ce ticket.',
        'Aucun ticket similaire trouvé.',
        'Aucune intervention pour ce ticket.',
        'Aucun historique pour ce ticket.',
        'Aucune action disponible pour ce ticket.',
    ] as $message) {
        ensureEmptyStates(str_contains($technicianShowHtml, $message), sprintf('Technician show empty state missing: %s', $message));
    }
    ensureEmptyStates(!str_contains($technicianShowHtml, 'M\'assigner ce ticket'), 'Technician show must not add an assignment action in the no-action scenario.');

    $technicianCategoryEmptyHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'technician/ticket_show.html.twig', [
        'ticket' => $ticket,
        'categories' => [],
        'allowed_statuses' => Ticket::ALLOWED_STATUSES,
        'allowed_priorities' => Ticket::ALLOWED_PRIORITIES,
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
        'can_update_status' => false,
        'can_update_priority' => false,
        'can_update_category' => true,
        'can_add_intervention' => false,
        'intervention_form' => null,
        'interventions' => [],
        'ticket_history' => [],
        'ai_analyses' => [],
        'similar_tickets' => [],
        'history_action_labels' => [],
    ], ['ROLE_TECHNICIAN'], '/technician/tickets/1', 'app_technician_ticket_show');
    ensureEmptyStates(str_contains($technicianCategoryEmptyHtml, 'Aucune catégorie'), 'Technician category empty state is missing when category update is available.');

    $adminDashboardHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'admin/index.html.twig', [
        'open_ticket_count' => 0,
        'open_unassigned_ticket_count' => 0,
        'in_progress_ticket_count' => 0,
        'resolved_ticket_count' => 0,
        'closed_ticket_count' => 0,
        'client_count' => 0,
        'technician_count' => 0,
        'category_count' => 0,
        'recent_tickets' => [],
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
    ], ['ROLE_ADMIN'], '/admin', 'app_admin');
    ensureContainsEmptyState($adminDashboardHtml, 'Aucun ticket récent à afficher.');

    $adminUsersHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'admin/users.html.twig', [
        'users' => [],
        'role_labels' => [],
    ], ['ROLE_ADMIN'], '/admin/users', 'app_admin_users');
    ensureContainsEmptyState($adminUsersHtml, 'Aucun utilisateur à afficher.');

    $adminCategoriesHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'admin/categories.html.twig', [
        'categories' => [],
    ], ['ROLE_ADMIN'], '/admin/categories', 'app_admin_categories');
    ensureContainsEmptyState($adminCategoriesHtml, 'Aucune catégorie disponible.');

    $adminStatisticsHtml = renderEmptyStateTemplate($twig, $requestStack, $tokenStorage, 'admin/statistics.html.twig', [
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
        'status_labels' => $statusLabels,
        'priority_labels' => $priorityLabels,
    ], ['ROLE_ADMIN'], '/admin/statistics', 'app_admin_statistics');
    ensureContainsEmptyState($adminStatisticsHtml, 'Aucun ticket catégorisé ou non catégorisé à afficher.');
    ensureEmptyStates(str_contains($adminStatisticsHtml, 'Total des tickets'), 'Admin statistics zero counters must remain visible.');

    foreach ([
        'src/Controller/ClientController.php',
        'src/Controller/ClientTicketController.php',
        'src/Controller/TechnicianController.php',
        'src/Controller/AdminController.php',
        'config/packages/security.yaml',
    ] as $file) {
        ensureEmptyStates(file_exists(dirname(__DIR__, 2).'/'.$file), sprintf('Expected file missing: %s', $file));
    }

    echo "Empty states tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);
    $kernel->shutdown();
}
