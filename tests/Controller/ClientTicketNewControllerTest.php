<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\Controller\ClientTicketController;
use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\TicketRepository;
use App\Service\TicketHistoryService;
use App\Service\TicketCategorySuggestionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
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

final class ClientTicketNewAIProvider implements AIProviderInterface
{
    public int $callCount = 0;
    public ?Throwable $failure = null;
    public string $suggestedCategory = 'Impression';

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        ++$this->callCount;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return new AIAnalysisResult(
            summary: 'Le problème concerne une imprimante.',
            suggestedPriority: Ticket::PRIORITY_HIGH,
            suggestedCategory: $this->suggestedCategory,
            keywords: ['imprimante'],
            suggestions: ['Vérifier la connexion de l’imprimante.'],
        );
    }
}

final class ClientTicketNewTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

function ensureClientTicketNew(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientTicketNewUser(string $email, array $roles = ['ROLE_CLIENT']): User
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

function clientTicketNewAuthenticatedRequest(TokenStorageInterface $tokenStorage, User $user, string $method, string $path): Request
{
    $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

    return Request::create($path, $method);
}

function clientTicketNewTokenFrom(string $html): string
{
    if (1 !== preg_match('/<input[^>]+name="ticket\[_token\]"[^>]*>/', $html, $inputMatches)) {
        throw new RuntimeException('Unable to extract the ticket CSRF token input.');
    }

    if (1 !== preg_match('/value="([^"]+)"/', $inputMatches[0], $matches)) {
        throw new RuntimeException('Unable to extract the ticket CSRF token.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

function clientTicketNewRequest(
    string $method,
    array $parameters,
    Session $session,
): Request {
    $server = [];

    if ('POST' === strtoupper($method)) {
        $server['HTTP_ORIGIN'] = 'http://localhost';
    }

    $request = Request::create('/client/ticket/new', $method, $parameters, [], [], $server);
    $request->setSession($session);

    return $request;
}

function clientTicketNewRender(
    ClientTicketController $controller,
    TicketRepository $ticketRepository,
    RequestStack $requestStack,
    Request $request,
    EntityManagerInterface $entityManager,
    TicketHistoryService $ticketHistoryService,
): Response {
    $requestStack->push($request);

    try {
        return $controller->new($request, $entityManager, $ticketHistoryService);
    } finally {
        $requestStack->pop();
    }
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
$containerController = $container->get(ClientTicketController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($containerController);
$aiProvider = new ClientTicketNewAIProvider();
$testLogger = new ClientTicketNewTestLogger();
$ticketHistoryService = new TicketHistoryService($entityManager);
$categoryRepository = $entityManager->getRepository(Category::class);
$controller = new ClientTicketController(
    new AIService($aiProvider, $entityManager),
    $testLogger,
    new TicketCategorySuggestionService($categoryRepository, $entityManager, $ticketHistoryService),
);
$controller->setContainer($controllerContainer);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);

$connection->beginTransaction();
$session = new Session(new MockArraySessionStorage());

try {
    $client = clientTicketNewUser('client-ticket-new@example.test');
    $technician = clientTicketNewUser('client-ticket-new-tech@example.test', ['ROLE_TECHNICIAN']);
    $impressionCategoryName = 'Impression '.bin2hex(random_bytes(6));
    $impressionCategory = (new Category())->setName($impressionCategoryName);
    $aiProvider->suggestedCategory = $impressionCategoryName;
    $entityManager->persist($client);
    $entityManager->persist($technician);
    $entityManager->persist($impressionCategory);
    $entityManager->flush();

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    $getResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('GET', [], $session),
        $entityManager,
        $ticketHistoryService,
    );
    $getHtml = $getResponse->getContent();

    ensureClientTicketNew(Response::HTTP_OK === $getResponse->getStatusCode(), 'GET /client/ticket/new must return 200 for ROLE_CLIENT.');
    ensureClientTicketNew(str_contains($getHtml, 'Nouvelle demande'), 'The Figma page title is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Expliquez-nous votre problème. Pilot AI aidera l’équipe technique à qualifier votre demande.'), 'The help text is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Titre de la demande *'), 'The title label is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Exemple : Impossible d’imprimer'), 'The title placeholder is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Décrivez votre problème *'), 'The description label is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Que se passe-t-il ? Depuis quand ? Quel équipement est concerné ?'), 'The description placeholder is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'Annuler'), 'The cancel action is missing.');
    ensureClientTicketNew(str_contains($getHtml, '/client/tickets'), 'The cancel action must link to the client ticket list.');
    ensureClientTicketNew(str_contains($getHtml, 'Envoyer ma demande'), 'The submit action is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'name="ticket[title]"'), 'The title field is missing.');
    ensureClientTicketNew(str_contains($getHtml, 'name="ticket[description]"'), 'The description field is missing.');

    foreach (['ticket[status]', 'ticket[priority]', 'ticket[source]', 'ticket[createdAt]', 'ticket[updatedAt]', 'ticket[createdBy]', 'ticket[category]', 'ticket[assignedTo]', 'TicketHistory', 'AIAnalysis'] as $forbiddenField) {
        ensureClientTicketNew(!str_contains($getHtml, $forbiddenField), sprintf('A system field was exposed in the form: %s', $forbiddenField));
    }

    $escapedHtml = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('POST', [
            'ticket' => [
                'title' => '<script>alert("new-ticket")</script>',
                'description' => '',
                '_token' => clientTicketNewTokenFrom($getHtml),
            ],
        ], $session),
        $entityManager,
        $ticketHistoryService,
    )->getContent();
    ensureClientTicketNew(str_contains($escapedHtml, '&lt;script&gt;alert(&quot;new-ticket&quot;)&lt;/script&gt;'), 'Submitted title values must be escaped.');
    ensureClientTicketNew(!str_contains($escapedHtml, '<script>alert("new-ticket")</script>'), 'An unescaped submitted title was rendered.');

    $ticketCountBeforeInvalid = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeInvalid = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');

    $invalidScenarios = [
        'blank title' => [
            'title' => '   ',
            'description' => 'Description valide',
            '_token' => clientTicketNewTokenFrom($getHtml),
        ],
        'blank description' => [
            'title' => 'Titre valide',
            'description' => '   ',
            '_token' => clientTicketNewTokenFrom($getHtml),
        ],
        'too long title' => [
            'title' => str_repeat('a', 256),
            'description' => 'Description valide',
            '_token' => clientTicketNewTokenFrom($getHtml),
        ],
        'too long description' => [
            'title' => 'Titre valide',
            'description' => str_repeat('a', 50001),
            '_token' => clientTicketNewTokenFrom($getHtml),
        ],
    ];

    foreach ($invalidScenarios as $name => $payload) {
        $response = clientTicketNewRender(
            $controller,
            $ticketRepository,
            $requestStack,
            clientTicketNewRequest('POST', ['ticket' => $payload], $session),
            $entityManager,
            $ticketHistoryService,
        );
        ensureClientTicketNew(
            in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY], true),
            sprintf('Invalid scenario "%s" must redisplay the form, got %d.', $name, $response->getStatusCode()),
        );
    }

    $invalidCsrfResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('POST', [
            'ticket' => [
                'title' => 'Titre valide',
                'description' => 'Description valide',
                '_token' => 'invalid-token',
            ],
        ], $session),
        $entityManager,
        $ticketHistoryService,
    );
    ensureClientTicketNew(
        Response::HTTP_FOUND !== $invalidCsrfResponse->getStatusCode(),
        'Invalid CSRF must not reach the success redirect.',
    );

    ensureClientTicketNew($ticketCountBeforeInvalid === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Invalid submissions must not create tickets.');
    ensureClientTicketNew($historyCountBeforeInvalid === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Invalid submissions must not create history entries.');
    ensureClientTicketNew(0 === $aiProvider->callCount, 'Invalid submissions must not trigger AI analysis.');

    $ticketCountBeforeSuccess = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeSuccess = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');
    $successResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('POST', [
            'ticket' => [
                'title' => 'Impossible d’imprimer',
                'description' => 'L’imprimante du secrétariat ne répond plus depuis ce matin.',
                '_token' => clientTicketNewTokenFrom($getHtml),
            ],
            'status' => Ticket::STATUS_CLOSED,
            'priority' => Ticket::PRIORITY_URGENT,
            'source' => 'EMAIL',
            'createdBy' => '999',
            'category' => '999',
            'assignedTo' => '999',
        ], $session),
        $entityManager,
        $ticketHistoryService,
    );

    ensureClientTicketNew(Response::HTTP_FOUND === $successResponse->getStatusCode(), 'Successful submission must redirect.');
    ensureClientTicketNew('/client/tickets' === $successResponse->headers->get('Location'), 'Successful submission must redirect to app_client_tickets.');
    ensureClientTicketNew($ticketCountBeforeSuccess + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Successful submission must create exactly one ticket.');
    ensureClientTicketNew($historyCountBeforeSuccess + 2 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Successful submission must create creation and automatic-category history entries.');
    ensureClientTicketNew(1 === $aiProvider->callCount, 'A successful ticket creation must trigger exactly one AI analysis.');

    /** @var Ticket|null $createdTicket */
    $createdTicket = $ticketRepository->findOneBy(['title' => 'Impossible d’imprimer']);
    ensureClientTicketNew($createdTicket instanceof Ticket, 'The created ticket was not found.');
    $analyses = $entityManager->getRepository(AIAnalysis::class)->findBy(['ticket' => $createdTicket]);
    ensureClientTicketNew(1 === count($analyses), 'A successful AI analysis must be persisted exactly once.');
    ensureClientTicketNew($createdTicket->getCreatedBy() === $client, 'createdBy must be the connected client.');
    ensureClientTicketNew(Ticket::STATUS_OPEN === $createdTicket->getStatus(), 'status must be OPEN.');
    ensureClientTicketNew(Ticket::PRIORITY_MEDIUM === $createdTicket->getPriority(), 'priority must be MEDIUM.');
    ensureClientTicketNew('APP' === $createdTicket->getSource(), 'source must be APP.');
    ensureClientTicketNew($createdTicket->getCreatedAt() instanceof DateTimeImmutable, 'createdAt must be generated server-side.');
    ensureClientTicketNew(null === $createdTicket->getUpdatedAt(), 'updatedAt must be null.');
    ensureClientTicketNew($impressionCategory === $createdTicket->getCategory(), 'A unique suggested category must be applied.');
    ensureClientTicketNew(null === $createdTicket->getAssignedTo(), 'assignedTo must remain null.');

    $historyAction = $connection->fetchOne(
        'SELECT action FROM ticket_history WHERE ticket_id = ? AND action = ?',
        [$createdTicket->getId(), 'TICKET_CREATED'],
    );
    ensureClientTicketNew('TICKET_CREATED' === $historyAction, 'TICKET_CREATED history must be recorded.');
    ensureClientTicketNew(
        1 === (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ? AND action = ? AND changed_by_id IS NULL AND old_value IS NULL AND new_value = ?',
            [$createdTicket->getId(), 'CATEGORY_AUTO_ASSIGNED', (string) $impressionCategory->getId()],
        ),
        'Automatic category assignment must be recorded as a system history event.',
    );

    $categoryCountBeforeNoMatch = (int) $connection->fetchOne('SELECT COUNT(*) FROM category');
    $aiProvider->suggestedCategory = 'Catégorie inexistante';
    $noMatchFormResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('GET', [], $session),
        $entityManager,
        $ticketHistoryService,
    );
    $noMatchTitle = 'Demande avec catégorie inconnue';
    $noMatchResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('POST', [
            'ticket' => [
                'title' => $noMatchTitle,
                'description' => 'Aucune catégorie existante ne correspond.',
                '_token' => clientTicketNewTokenFrom((string) $noMatchFormResponse->getContent()),
            ],
        ], $session),
        $entityManager,
        $ticketHistoryService,
    );
    ensureClientTicketNew(Response::HTTP_FOUND === $noMatchResponse->getStatusCode(), 'A missing category match must not block client ticket creation.');
    ensureClientTicketNew(2 === $aiProvider->callCount, 'Each successful client creation must make only one AI call.');
    $noMatchTicket = $ticketRepository->findOneBy(['title' => $noMatchTitle]);
    ensureClientTicketNew($noMatchTicket instanceof Ticket && null === $noMatchTicket->getCategory(), 'A missing category match must leave the ticket uncategorized.');
    ensureClientTicketNew($categoryCountBeforeNoMatch === (int) $connection->fetchOne('SELECT COUNT(*) FROM category'), 'A missing category match must not create a category.');
    ensureClientTicketNew(
        0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ? AND action = ?', [$noMatchTicket->getId(), 'CATEGORY_AUTO_ASSIGNED']),
        'No automatic category history should be created when no category matches.',
    );

    $failedTitle = 'Sujet sensible pour analyse indisponible';
    $failedDescription = 'Contenu sensible qui ne doit pas apparaître dans les journaux.';
    $ticketCountBeforeAIError = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeAIError = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');
    $aiCallsBeforeError = $aiProvider->callCount;
    $aiProvider->failure = new AIProviderException('Simulated provider failure.');

    $failureResponse = clientTicketNewRender(
        $controller,
        $ticketRepository,
        $requestStack,
        clientTicketNewRequest('POST', [
            'ticket' => [
                'title' => $failedTitle,
                'description' => $failedDescription,
                '_token' => clientTicketNewTokenFrom($getHtml),
            ],
        ], $session),
        $entityManager,
        $ticketHistoryService,
    );

    ensureClientTicketNew(Response::HTTP_FOUND === $failureResponse->getStatusCode(), 'An AI failure must not block the normal redirect.');
    ensureClientTicketNew('/client/tickets' === $failureResponse->headers->get('Location'), 'An AI failure must preserve the normal redirect destination.');
    ensureClientTicketNew($ticketCountBeforeAIError + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'An AI failure must not prevent ticket creation.');
    ensureClientTicketNew($historyCountBeforeAIError + 1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'An AI failure must not prevent history creation.');
    ensureClientTicketNew($aiCallsBeforeError + 1 === $aiProvider->callCount, 'The failed analysis must be attempted only once.');

    $failedTicket = $ticketRepository->findOneBy(['title' => $failedTitle]);
    ensureClientTicketNew($failedTicket instanceof Ticket, 'The ticket must remain persisted after an AI failure.');
    ensureClientTicketNew([] === $entityManager->getRepository(AIAnalysis::class)->findBy(['ticket' => $failedTicket]), 'A failed AI analysis must not create an analysis record.');

    $historyAction = $connection->fetchOne(
        'SELECT action FROM ticket_history WHERE ticket_id = ? ORDER BY id DESC LIMIT 1',
        [$failedTicket->getId()],
    );
    ensureClientTicketNew('TICKET_CREATED' === $historyAction, 'The ticket creation history must remain after an AI failure.');
    ensureClientTicketNew(in_array('Votre demande a bien été envoyée.', $session->getFlashBag()->peek('success'), true), 'An AI failure must not replace the normal success message.');
    $failureFlashes = $session->getFlashBag()->peekAll();
    ensureClientTicketNew(
        !isset($failureFlashes['error']) && !isset($failureFlashes['danger']),
        'An AI failure must not expose a technical error flash to the client.',
    );

    $loggedData = json_encode($testLogger->records, JSON_THROW_ON_ERROR);
    ensureClientTicketNew(!str_contains($loggedData, $failedTitle), 'AI failure logs must not contain the ticket title.');
    ensureClientTicketNew(!str_contains($loggedData, $failedDescription), 'AI failure logs must not contain the ticket description.');
    ensureClientTicketNew(
        in_array(AIProviderException::class, array_column(array_column($testLogger->records, 'context'), 'exception'), true),
        'AI failure logs must include the exception class for diagnosis.',
    );

    $flashes = $session->getFlashBag()->peek('success');
    ensureClientTicketNew(in_array('Votre demande a bien été envoyée.', $flashes, true), 'The success flash is missing.');

    $tokenStorage->setToken(null);
    $anonymousResponse = $kernel->handle(Request::create('/client/ticket/new', 'GET'));
    ensureClientTicketNew(
        in_array($anonymousResponse->getStatusCode(), [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN], true),
        sprintf('Unauthenticated GET /client/ticket/new returned unexpected status %d.', $anonymousResponse->getStatusCode()),
    );
    if (Response::HTTP_FOUND === $anonymousResponse->getStatusCode()) {
        ensureClientTicketNew(
            str_contains((string) $anonymousResponse->headers->get('Location'), '/login'),
            'Unauthenticated GET /client/ticket/new must redirect to login.',
        );
    }

    $roleResponse = $kernel->handle(clientTicketNewAuthenticatedRequest($tokenStorage, $technician, 'GET', '/client/ticket/new'), HttpKernelInterface::SUB_REQUEST);
    ensureClientTicketNew(
        Response::HTTP_FORBIDDEN === $roleResponse->getStatusCode(),
        sprintf('A non-client role must be denied access to /client/ticket/new (got %d).', $roleResponse->getStatusCode()),
    );

    $ticketCountBeforeRolePost = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $rolePostResponse = $kernel->handle(clientTicketNewAuthenticatedRequest($tokenStorage, $technician, 'POST', '/client/ticket/new'), HttpKernelInterface::SUB_REQUEST);
    ensureClientTicketNew(Response::HTTP_FORBIDDEN === $rolePostResponse->getStatusCode(), 'A non-client role must not create a ticket.');
    ensureClientTicketNew($ticketCountBeforeRolePost === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'A non-client role created a ticket.');

    echo "Client ticket creation tests: PASS\n";
} finally {
    $tokenStorage->setToken(null);

    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
