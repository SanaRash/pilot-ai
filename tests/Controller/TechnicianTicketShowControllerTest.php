<?php

declare(strict_types=1);

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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureTechnicianTicketShow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicianTicketShowUser(
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

function technicianTicketShowTicket(
    User $client,
    string $title,
    string $status,
    DateTimeImmutable $createdAt,
    ?User $assignedTo = null,
    ?Category $category = null,
    string $description = 'Description de test',
    string $priority = Ticket::PRIORITY_MEDIUM,
): Ticket {
    return (new Ticket())
        ->setTitle($title)
        ->setDescription($description)
        ->setStatus($status)
        ->setPriority($priority)
        ->setSource('APP')
        ->setCreatedAt($createdAt)
        ->setCreatedBy($client)
        ->setAssignedTo($assignedTo)
        ->setCategory($category);
}

function renderTechnicianTicketShow(
    TechnicianController $controller,
    TokenStorageInterface $tokenStorage,
    User $user,
    Ticket $ticket,
    CategoryRepository $categoryRepository,
    InterventionRepository $interventionRepository,
    TicketHistoryRepository $ticketHistoryRepository,
    AIAnalysisRepository $aiAnalysisRepository,
): string {
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    $response = $controller->show(
        $ticket,
        $categoryRepository,
        $interventionRepository,
        $ticketHistoryRepository,
        $aiAnalysisRepository,
    );

    ensureTechnicianTicketShow(Response::HTTP_OK === $response->getStatusCode(), 'The technician ticket detail must render with HTTP 200.');

    return $response->getContent();
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
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);
/** @var InterventionRepository $interventionRepository */
$interventionRepository = $entityManager->getRepository(Intervention::class);
/** @var TicketHistoryRepository $ticketHistoryRepository */
$ticketHistoryRepository = $entityManager->getRepository(TicketHistory::class);
/** @var AIAnalysisRepository $aiAnalysisRepository */
$aiAnalysisRepository = $entityManager->getRepository(AIAnalysis::class);

$connection->beginTransaction();
$testRequest = Request::create('/technician/tickets/1', 'GET');
$testRequest->setSession(new Session(new MockArraySessionStorage()));
$requestStack->push($testRequest);

try {
    $technician = technicianTicketShowUser('show-tech@example.test', ['ROLE_TECHNICIAN']);
    $otherTechnician = technicianTicketShowUser('show-other-tech@example.test', ['ROLE_TECHNICIAN'], 'Autre', 'Tech');
    $client = technicianTicketShowUser(
        'show-client@example.test',
        ['ROLE_CLIENT'],
        '<script>alert("client")</script>',
        'Client',
    );
    $category = (new Category())->setName('<script>alert("category")</script>');
    $otherCategory = (new Category())->setName('Réseau');

    foreach ([$technician, $otherTechnician, $client, $category, $otherCategory] as $entity) {
        $entityManager->persist($entity);
    }

    $freeTicket = technicianTicketShowTicket(
        $client,
        '<script>alert("free")</script>',
        Ticket::STATUS_OPEN,
        new DateTimeImmutable('2099-12-01 10:00:00'),
        null,
        null,
        '<script>alert("description")</script>',
        Ticket::PRIORITY_HIGH,
    );
    $assignedTicket = technicianTicketShowTicket(
        $client,
        'Ticket assigné courant',
        Ticket::STATUS_IN_PROGRESS,
        new DateTimeImmutable('2099-12-02 10:00:00'),
        $technician,
        $category,
        'Description assignée',
        Ticket::PRIORITY_URGENT,
    );
    $assignedTicket->setSource('EMAIL')->setRequesterEmail('<script>alert("requester")</script>@example.test');
    $otherAssignedTicket = technicianTicketShowTicket(
        $client,
        'Ticket autre technicien',
        Ticket::STATUS_RESOLVED,
        new DateTimeImmutable('2099-12-03 10:00:00'),
        $otherTechnician,
        $otherCategory,
        'Description autre',
        Ticket::PRIORITY_LOW,
    );
    $similarTicket = technicianTicketShowTicket(
        $client,
        'Ticket similaire visible',
        Ticket::STATUS_OPEN,
        new DateTimeImmutable('2099-12-04 10:00:00'),
        null,
        $category,
        'Description assignée',
        Ticket::PRIORITY_MEDIUM,
    );

    foreach ([$freeTicket, $assignedTicket, $otherAssignedTicket, $similarTicket] as $ticket) {
        $entityManager->persist($ticket);
    }

    $analysis = (new AIAnalysis())
        ->setTicket($assignedTicket)
        ->setSummary('Résumé IA visible')
        ->setSuggestedPriority(Ticket::PRIORITY_HIGH)
        ->setSuggestedCategory('Imprimante')
        ->setKeywords(['mot-clé'])
        ->setSuggestions(['Suggestion passive'])
        ->setCreatedAt(new DateTimeImmutable('2099-12-05 10:00:00'));
    $intervention = (new Intervention())
        ->setTicket($assignedTicket)
        ->setTechnician($technician)
        ->setContent('Intervention visible')
        ->setCreatedAt(new DateTimeImmutable('2099-12-06 10:00:00'));
    $history = (new TicketHistory())
        ->setTicket($assignedTicket)
        ->setAction('STATUS_CHANGED')
        ->setOldValue(Ticket::STATUS_OPEN)
        ->setNewValue(Ticket::STATUS_IN_PROGRESS)
        ->setChangedBy($technician)
        ->setCreatedAt(new DateTimeImmutable('2099-12-07 10:00:00'));

    foreach ([$analysis, $intervention, $history] as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();
    $autoCategoryHistory = (new TicketHistory())
        ->setTicket($assignedTicket)
        ->setAction('CATEGORY_AUTO_ASSIGNED')
        ->setOldValue(null)
        ->setNewValue((string) $category->getId())
        ->setChangedBy(null)
        ->setCreatedAt(new DateTimeImmutable('2099-12-08 10:00:00'));
    $entityManager->persist($autoCategoryHistory);
    $entityManager->flush();

    $ticketCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');
    $interventionCountBeforeRender = (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention');

    $freeHtml = renderTechnicianTicketShow($controller, $tokenStorage, $technician, $freeTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository);
    ensureTechnicianTicketShow(str_contains($freeHtml, 'M&#039;assigner ce ticket') || str_contains($freeHtml, "M'assigner ce ticket"), 'The assignment button must be visible for an unassigned ticket.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, 'Modifier le statut'), 'Status form must be hidden for an unassigned ticket.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, 'Modifier la priorité'), 'Priority form must be hidden for an unassigned ticket.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, 'Modifier la catégorie'), 'Category form must be hidden for an unassigned ticket.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, 'Ajouter une intervention'), 'Intervention form must be hidden for an unassigned ticket.');
    ensureTechnicianTicketShow(str_contains($freeHtml, '&lt;script&gt;alert(&quot;free&quot;)&lt;/script&gt;'), 'Ticket title must be escaped.');
    ensureTechnicianTicketShow(str_contains($freeHtml, '&lt;script&gt;alert(&quot;description&quot;)&lt;/script&gt;'), 'Ticket description must be escaped.');
    ensureTechnicianTicketShow(str_contains($freeHtml, 'Ouvert'), 'OPEN must be translated.');
    ensureTechnicianTicketShow(str_contains($freeHtml, 'Haute'), 'HIGH priority must be translated.');
    ensureTechnicianTicketShow(str_contains($freeHtml, 'Non catégorisé'), 'Missing category must be displayed.');
    ensureTechnicianTicketShow(str_contains($freeHtml, 'Non assigné'), 'Unassigned ticket must display Non assigné.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, '>APP<'), 'Source must not be displayed.');
    ensureTechnicianTicketShow(!str_contains($freeHtml, 'Expéditeur e-mail'), 'App tickets must not display a requester email.');

    $assignedHtml = renderTechnicianTicketShow($controller, $tokenStorage, $technician, $assignedTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository);
    ensureTechnicianTicketShow(!str_contains($assignedHtml, "M'assigner ce ticket"), 'Assignment button must be hidden for the assigned technician.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Modifier le statut'), 'Status form must be visible for the assigned technician.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Modifier la priorité'), 'Priority form must be visible for the assigned technician.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Modifier la catégorie'), 'Category form must be visible for the assigned technician.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Ajouter une intervention'), 'Intervention form must be visible for the assigned technician.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'name="_token"'), 'CSRF tokens must remain present.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'En cours'), 'IN_PROGRESS must be translated.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Urgente'), 'URGENT priority must be translated.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '&lt;script&gt;alert(&quot;category&quot;)&lt;/script&gt;'), 'Category must be escaped.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt; Client'), 'Client identity must be escaped.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Expéditeur e-mail'), 'Email requester label must be visible for email tickets.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '&lt;script&gt;alert(&quot;requester&quot;)&lt;/script&gt;@example.test'), 'Email requester must be escaped.');
    ensureTechnicianTicketShow(!str_contains($assignedHtml, '<script>alert("requester")</script>@example.test'), 'Raw email requester must not be rendered.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'class="role-navigation__link" href="/technician">Dashboard</a>'), 'Global technician dashboard link is missing.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'class="role-navigation__link" href="/technician/tickets"'), 'Global open tickets link is missing.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'class="role-navigation__link" href="/technician/tickets/assigned"'), 'Global assigned tickets link is missing.');
    ensureTechnicianTicketShow(!str_contains($assignedHtml, 'Navigation technicien locale'), 'Local technician navigation must not be duplicated.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '← Retour aux tickets ouverts'), 'Return link to open tickets is missing.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Résumé IA visible'), 'AI analysis must remain visible.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Suggestion passive'), 'AI suggestions must remain visible and passive.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Ticket similaire visible'), 'Similar tickets must remain visible.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Intervention visible'), 'Interventions must remain visible.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Statut modifié'), 'History must remain visible.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Catégorie suggérée par l’IA'), 'Automatic category history must have its own label.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Système'), 'Automatic category history must be attributed to the system.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, 'Catégorie #'.$category->getId()), 'Automatic category history must display the category identifier.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '2099') || str_contains($assignedHtml, '06/12/2099'), 'Dates must be displayed.');
    ensureTechnicianTicketShow(!str_contains($assignedHtml, '>APP<'), 'Source must not be displayed on assigned detail.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '>Ouvert<') || str_contains($assignedHtml, 'Ouvert'), 'Status select labels must be translated.');
    ensureTechnicianTicketShow(str_contains($assignedHtml, '>Basse<') || str_contains($assignedHtml, 'Basse'), 'Priority select labels must be translated.');

    $otherHtml = renderTechnicianTicketShow($controller, $tokenStorage, $technician, $otherAssignedTicket, $categoryRepository, $interventionRepository, $ticketHistoryRepository, $aiAnalysisRepository);
    ensureTechnicianTicketShow(str_contains($otherHtml, 'Ticket autre technicien'), 'Ticket assigned to another technician must remain consultable.');
    ensureTechnicianTicketShow(!str_contains($otherHtml, "M'assigner ce ticket"), 'Assignment button must be hidden for another technician ticket.');
    ensureTechnicianTicketShow(!str_contains($otherHtml, 'Modifier le statut'), 'Status form must be hidden for another technician ticket.');
    ensureTechnicianTicketShow(!str_contains($otherHtml, 'Modifier la priorité'), 'Priority form must be hidden for another technician ticket.');
    ensureTechnicianTicketShow(!str_contains($otherHtml, 'Modifier la catégorie'), 'Category form must be hidden for another technician ticket.');
    ensureTechnicianTicketShow(!str_contains($otherHtml, 'Ajouter une intervention'), 'Intervention form must be hidden for another technician ticket.');
    ensureTechnicianTicketShow(str_contains($otherHtml, 'Aucune action disponible pour ce ticket.'), 'No-action state must be explicit for another technician ticket.');

    $forbiddenRequest = Request::create('/technician/tickets/'.$otherAssignedTicket->getId().'/status', 'POST', [
        '_token' => 'invalid-test-token',
        'status' => Ticket::STATUS_CLOSED,
    ]);
    $forbiddenRequest->setSession(new Session(new MockArraySessionStorage()));
    $requestStack->push($forbiddenRequest);
    try {
        $controller->updateStatus($otherAssignedTicket, $forbiddenRequest, new App\Service\TicketHistoryService($entityManager));
        ensureTechnicianTicketShow(false, 'A technician who is not assigned must not update status.');
    } catch (AccessDeniedException) {
        ensureTechnicianTicketShow(true, 'Forbidden status update was rejected.');
    } finally {
        $requestStack->pop();
    }

    ensureTechnicianTicketShow($ticketCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Rendering the detail must not create tickets.');
    ensureTechnicianTicketShow($historyCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Rendering the detail must not create history.');
    ensureTechnicianTicketShow($interventionCountBeforeRender === (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention'), 'Rendering the detail must not create interventions.');

    echo "Technician ticket show tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);

    while (null !== $requestStack->getCurrentRequest()) {
        $requestStack->pop();
    }

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
