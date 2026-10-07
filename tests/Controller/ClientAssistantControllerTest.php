<?php

declare(strict_types=1);

use App\AI\Assistant\AssistantException;
use App\AI\Assistant\AssistantHistoryMessage;
use App\AI\Assistant\AssistantInput;
use App\AI\Assistant\AssistantProviderInterface;
use App\AI\Assistant\AssistantReply;
use App\AI\Assistant\AssistantService;
use App\Controller\ClientAssistantController;
use App\Entity\Category;
use App\Entity\KnowledgeArticle;
use App\Entity\Ticket;
use App\Entity\User;
use App\Kernel;
use App\Repository\AIAnalysisRepository;
use App\Repository\KnowledgeArticleRepository;
use App\Repository\TicketRepository;
use App\Service\KnowledgeSearchService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureClientAssistant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clientAssistantUser(string $email, array $roles): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname('Client')
        ->setLastname('Assistant')
        ->setRoles($roles)
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-01-01 09:00:00'));
}

function clientAssistantTicket(User $client, string $title, ?Category $category = null): Ticket
{
    return (new Ticket())
        ->setTitle($title)
        ->setDescription('Description ticket assistant')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2026-01-02 10:00:00'))
        ->setCreatedBy($client)
        ->setCategory($category);
}

final class ClientAssistantTestProvider implements AssistantProviderInterface
{
    public int $callCount = 0;
    public ?AssistantInput $lastInput = null;
    public bool $unavailable = false;

    public function __construct(
        private readonly string $answer = 'Réponse <script>assistant</script>',
        private readonly bool $needsTechnician = false,
    ) {
    }

    public function generateReply(AssistantInput $input): AssistantReply
    {
        ++$this->callCount;
        $this->lastInput = $input;

        if ($this->unavailable) {
            throw new AssistantException('Provider unavailable with secret payload.');
        }

        return new AssistantReply($this->answer, $this->needsTechnician);
    }
}

final class ClientAssistantTestLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ('warning' === (string) $level) {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }

    public function emergency(\Stringable|string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log('debug', $message, $context); }
}

function clientAssistantRequest(Session $session, string $csrfToken, mixed $payload): Request
{
    $content = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
    $request = Request::create(
        '/client/assistant/message',
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ],
        $content,
    );
    $request->attributes->set('_route', 'app_client_assistant_message');
    $request->setSession($session);

    return $request;
}

function clientAssistantCall(
    ClientAssistantController $controller,
    RequestStack $requestStack,
    Request $request,
    TicketRepository $ticketRepository,
    KnowledgeSearchService $knowledgeSearchService,
    AssistantService $assistantService,
    LoggerInterface $logger,
): Response {
    $requestStack->push($request);
    try {
        return $controller->message($request, $ticketRepository, $knowledgeSearchService, $assistantService, $logger);
    } finally {
        $requestStack->pop();
    }
}

function clientAssistantDraftCall(
    ClientAssistantController $controller,
    RequestStack $requestStack,
    Request $request,
): Response {
    $requestStack->push($request);
    try {
        return $controller->ticketDraft($request);
    } finally {
        $requestStack->pop();
    }
}

function clientAssistantJson(Response $response): array
{
    $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    ensureClientAssistant(is_array($decoded), 'The assistant response must be JSON object-like.');

    return $decoded;
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var ClientAssistantController $controller */
$controller = $container->get(ClientAssistantController::class);
$controllerContainerProperty = new ReflectionProperty(AbstractController::class, 'container');
/** @var ContainerInterface $controllerContainer */
$controllerContainer = $controllerContainerProperty->getValue($controller);
/** @var TokenStorageInterface $tokenStorage */
$tokenStorage = $controllerContainer->get('security.token_storage');
/** @var RequestStack $requestStack */
$requestStack = $controllerContainer->get('request_stack');
/** @var CsrfTokenManagerInterface $csrfTokenManager */
$csrfTokenManager = $controllerContainer->get('security.csrf.token_manager');
/** @var TicketRepository $ticketRepository */
$ticketRepository = $entityManager->getRepository(Ticket::class);
/** @var KnowledgeArticleRepository $knowledgeArticleRepository */
$knowledgeArticleRepository = $entityManager->getRepository(KnowledgeArticle::class);
/** @var AIAnalysisRepository $aiAnalysisRepository */
$aiAnalysisRepository = $entityManager->getRepository(\App\Entity\AIAnalysis::class);
$knowledgeSearchService = new KnowledgeSearchService($knowledgeArticleRepository, $aiAnalysisRepository);
$provider = new ClientAssistantTestProvider();
$assistantService = new AssistantService($provider);
$logger = new ClientAssistantTestLogger();
$session = new Session(new MockArraySessionStorage());

$connection->beginTransaction();
try {
    $client = clientAssistantUser('assistant-client-'.bin2hex(random_bytes(5)).'@example.test', ['ROLE_CLIENT']);
    $otherClient = clientAssistantUser('assistant-other-'.bin2hex(random_bytes(5)).'@example.test', ['ROLE_CLIENT']);
    $technician = clientAssistantUser('assistant-tech-'.bin2hex(random_bytes(5)).'@example.test', ['ROLE_TECHNICIAN']);
    $admin = clientAssistantUser('assistant-admin-'.bin2hex(random_bytes(5)).'@example.test', ['ROLE_ADMIN']);
    $category = (new Category())->setName('Réseau assistant');
    foreach ([$client, $otherClient, $technician, $admin, $category] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $ticket = clientAssistantTicket($client, 'Ticket Wi-Fi assistant', $category);
    $foreignTicket = clientAssistantTicket($otherClient, 'Ticket autre client assistant', $category);
    $matchingArticle = (new KnowledgeArticle('Aide Wi-Fi', 'Procédure wifi client-safe.', ['wifi']))
        ->setCategory($category)
        ->setIsActive(true)
        ->setIsClientSafe(true);
    $inactiveArticle = (new KnowledgeArticle('Article inactif', 'wifi inactif', ['wifi']))
        ->setIsActive(false)
        ->setIsClientSafe(true);
    $internalArticle = (new KnowledgeArticle('Article interne', 'wifi interne', ['wifi']))
        ->setIsActive(true)
        ->setIsClientSafe(false);
    foreach ([$ticket, $foreignTicket, $matchingArticle, $inactiveArticle, $internalArticle] as $entity) {
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    $csrfRequest = Request::create('/client', 'GET');
    $csrfRequest->setSession($session);
    $requestStack->push($csrfRequest);
    try {
        $csrfToken = $csrfTokenManager->getToken('client-assistant-message')->getValue();
    } finally {
        $requestStack->pop();
    }

    $countsBefore = [
        'tickets' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'),
        'ticket_messages' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_message'),
        'ticket_history' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'),
    ];
    $response = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => '  Mon wifi coupe  ', 'ticketId' => $ticket->getId()]),
        $ticketRepository,
        $knowledgeSearchService,
        $assistantService,
        $logger,
    );
    $json = clientAssistantJson($response);

    ensureClientAssistant(Response::HTTP_OK === $response->getStatusCode(), 'A valid assistant message must return 200.');
    ensureClientAssistant(true === $json['success'], 'A valid assistant message must return success=true.');
    ensureClientAssistant('BOT' === $json['message']['author'], 'The assistant JSON response must use BOT author.');
    ensureClientAssistant('Réponse <script>assistant</script>' === $json['message']['content'], 'The assistant response content must be returned without server-side rewriting.');
    ensureClientAssistant(false === $json['needsTechnician'], 'The assistant JSON response must expose the structured needsTechnician flag.');
    ensureClientAssistant(1 === $provider->callCount, 'The assistant provider must be called exactly once.');
    ensureClientAssistant($provider->lastInput instanceof AssistantInput, 'The provider input must be captured.');
    ensureClientAssistant('Mon wifi coupe' === $provider->lastInput->clientMessage, 'The client message must be trimmed before provider call.');
    ensureClientAssistant('Ticket Wi-Fi assistant' === $provider->lastInput->ticketTitle, 'The owned ticket context must be passed.');
    ensureClientAssistant(1 === count($provider->lastInput->knowledgeArticles), 'Only selected client-safe active knowledge articles must reach the assistant.');
    ensureClientAssistant('Aide Wi-Fi' === $provider->lastInput->knowledgeArticles[0]->getTitle(), 'The matching knowledge article must be selected.');
    ensureClientAssistant([] === $provider->lastInput->history, 'Missing history must be accepted as an empty conversation history.');
    ensureClientAssistant(Ticket::STATUS_OPEN === $ticket->getStatus(), 'The assistant must not mutate ticket status.');
    ensureClientAssistant(Ticket::PRIORITY_MEDIUM === $ticket->getPriority(), 'The assistant must not mutate ticket priority.');
    ensureClientAssistant($category === $ticket->getCategory(), 'The assistant must not mutate ticket category.');
    ensureClientAssistant(null === $ticket->getAssignedTo(), 'The assistant must not assign a ticket.');
    ensureClientAssistant($countsBefore['tickets'] === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'The assistant must not create tickets.');
    ensureClientAssistant($countsBefore['ticket_messages'] === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_message'), 'The global assistant must not persist TicketMessage.');
    ensureClientAssistant($countsBefore['ticket_history'] === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'The assistant must not create TicketHistory.');

    $globalProvider = new ClientAssistantTestProvider();
    $globalResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'wifi global']),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($globalProvider),
        $logger,
    );
    ensureClientAssistant(Response::HTTP_OK === $globalResponse->getStatusCode(), 'The assistant must accept message-only global requests.');
    ensureClientAssistant($globalProvider->lastInput instanceof AssistantInput, 'The global assistant input must be captured.');
    ensureClientAssistant(null === $globalProvider->lastInput->ticketTitle, 'The global assistant must not fabricate ticket title.');

    $validHistory = [
        ['content' => ' Première question ', 'role' => 'user'],
        ['role' => 'assistant', 'content' => 'Première réponse'],
    ];
    $historyProvider = new ClientAssistantTestProvider();
    $historyResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'wifi avec historique', 'history' => $validHistory]),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($historyProvider),
        $logger,
    );
    ensureClientAssistant(Response::HTTP_OK === $historyResponse->getStatusCode(), 'A valid short history must be accepted.');
    ensureClientAssistant($historyProvider->lastInput instanceof AssistantInput, 'The history provider input must be captured.');
    ensureClientAssistant(2 === count($historyProvider->lastInput->history), 'The valid history must be transmitted to the provider.');
    ensureClientAssistant($historyProvider->lastInput->history[0] instanceof AssistantHistoryMessage, 'History entries must be typed before reaching the provider.');
    ensureClientAssistant('Première question' === $historyProvider->lastInput->history[0]->content, 'History content must be trimmed.');
    ensureClientAssistant(AssistantHistoryMessage::ROLE_ASSISTANT === $historyProvider->lastInput->history[1]->role, 'Assistant history role must be preserved.');

    $sixHistory = [];
    for ($index = 0; $index < 6; ++$index) {
        $sixHistory[] = ['role' => 0 === $index % 2 ? 'user' : 'assistant', 'content' => 'Message '.$index];
    }
    $sixHistoryProvider = new ClientAssistantTestProvider();
    $sixHistoryResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'wifi six', 'history' => $sixHistory]),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($sixHistoryProvider),
        $logger,
    );
    ensureClientAssistant(Response::HTTP_OK === $sixHistoryResponse->getStatusCode(), 'Six history messages must be accepted.');
    ensureClientAssistant(6 === count($sixHistoryProvider->lastInput?->history ?? []), 'Exactly six history messages must reach the provider.');

    $secondTurnProvider = new ClientAssistantTestProvider('Réponse structurée du deuxième tour.', true);
    $secondTurnHistory = [
        [
            'role' => 'user',
            'content' => 'Mon ordinateur se connecte au Wi-Fi mais je n’ai pas Internet. J’ai déjà redémarré le Wi-Fi sur mon PC et ça ne change rien. Que puis-je faire ?',
        ],
        [
            'role' => 'assistant',
            'content' => str_repeat('a', 1_134),
        ],
    ];
    $secondTurnResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, [
            'message' => 'J’ai essayé mais ça ne fonctionne toujours pas.',
            'history' => $secondTurnHistory,
        ]),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($secondTurnProvider),
        $logger,
    );
    $secondTurnJson = clientAssistantJson($secondTurnResponse);
    ensureClientAssistant(Response::HTTP_OK === $secondTurnResponse->getStatusCode(), 'A second assistant turn with a valid provider-length assistant history answer must return 200.');
    ensureClientAssistant(1 === $secondTurnProvider->callCount, 'The second assistant turn must call the provider exactly once.');
    ensureClientAssistant($secondTurnProvider->lastInput instanceof AssistantInput, 'The second assistant turn provider input must be captured.');
    ensureClientAssistant('J’ai essayé mais ça ne fonctionne toujours pas.' === $secondTurnProvider->lastInput->clientMessage, 'The second assistant turn current message must not be duplicated into history.');
    ensureClientAssistant(2 === count($secondTurnProvider->lastInput->history), 'The second assistant turn history must be accepted and transmitted.');
    ensureClientAssistant(true === $secondTurnJson['needsTechnician'], 'The second assistant turn must preserve the structured needsTechnician=true result.');

    $invalidHistoryCases = [
        'too many messages' => array_fill(0, 7, ['role' => 'user', 'content' => 'Message']),
        'invalid role' => [['role' => 'system', 'content' => 'Message']],
        'empty content' => [['role' => 'user', 'content' => '   ']],
        'too long user content' => [['role' => 'user', 'content' => str_repeat('a', 1_001)]],
        'too long assistant content' => [['role' => 'assistant', 'content' => str_repeat('a', 2_001)]],
        'too long total' => array_fill(0, 5, ['role' => 'user', 'content' => str_repeat('a', 900)]),
        'extra field' => [['role' => 'user', 'content' => 'Message', 'unexpected' => 'field']],
        'non list' => ['first' => ['role' => 'user', 'content' => 'Message']],
    ];
    foreach ($invalidHistoryCases as $caseName => $historyPayload) {
        $invalidHistoryProvider = new ClientAssistantTestProvider();
        $invalidHistoryResponse = clientAssistantCall(
            $controller,
            $requestStack,
            clientAssistantRequest($session, $csrfToken, ['message' => 'wifi', 'history' => $historyPayload]),
            $ticketRepository,
            $knowledgeSearchService,
            new AssistantService($invalidHistoryProvider),
            $logger,
        );
        ensureClientAssistant(Response::HTTP_UNPROCESSABLE_ENTITY === $invalidHistoryResponse->getStatusCode(), sprintf('Invalid history case "%s" must return 422.', $caseName));
        ensureClientAssistant(0 === $invalidHistoryProvider->callCount, sprintf('Invalid history case "%s" must not call the provider.', $caseName));
    }

    $noArticleProvider = new ClientAssistantTestProvider('Réponse sans article', false);
    $noArticleResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'sujet sans correspondance xylophone']),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($noArticleProvider),
        $logger,
    );
    $noArticleJson = clientAssistantJson($noArticleResponse);
    ensureClientAssistant(Response::HTTP_OK === $noArticleResponse->getStatusCode(), 'No-knowledge requests must still return a clean assistant response.');
    ensureClientAssistant(0 === count($noArticleProvider->lastInput?->knowledgeArticles ?? []), 'The no-knowledge scenario must reach the provider without articles.');
    ensureClientAssistant(true === $noArticleJson['needsTechnician'], 'No retained knowledge article must force needsTechnician=true.');

    $needsTechnicianProvider = new ClientAssistantTestProvider('Réponse avec article', true);
    $needsTechnicianResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'wifi article']),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($needsTechnicianProvider),
        $logger,
    );
    $needsTechnicianJson = clientAssistantJson($needsTechnicianResponse);
    ensureClientAssistant(true === $needsTechnicianJson['needsTechnician'], 'When knowledge exists, the provider needsTechnician=true value must be preserved.');

    $ticketCountBeforeDraft = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket');
    $historyCountBeforeDraft = (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history');
    $draftResponse = clientAssistantDraftCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, [
            'question' => 'Je suis connecté au Wi-Fi mais je n’ai pas Internet, que dois-je vérifier ?',
            'answer' => "**Vérifiez la connexion réseau**\n1. **Redémarrez** la box puis `réactivez` le Wi-Fi.",
            'history' => [
                ['role' => 'user', 'content' => 'Bonjour'],
                ['role' => 'assistant', 'content' => 'Bonjour, je vous écoute.'],
            ],
        ]),
    );
    $draftJson = clientAssistantJson($draftResponse);
    ensureClientAssistant(Response::HTTP_OK === $draftResponse->getStatusCode(), 'A valid JSON assistant ticket draft must return 200.');
    ensureClientAssistant(true === $draftJson['success'], 'A valid JSON assistant ticket draft must return success=true.');
    ensureClientAssistant('/client/ticket/new' === $draftJson['redirectUrl'], 'A valid JSON assistant ticket draft must return the existing ticket creation form URL.');
    ensureClientAssistant($ticketCountBeforeDraft === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Creating an assistant draft must not create a ticket.');
    ensureClientAssistant($historyCountBeforeDraft === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'), 'Creating an assistant draft must not create TicketHistory.');
    $storedDraft = $session->get(ClientAssistantController::TICKET_DRAFT_SESSION_KEY);
    ensureClientAssistant(is_array($storedDraft), 'The assistant draft must be stored in the server session.');
    ensureClientAssistant('Je suis connecté au Wi-Fi mais je n’ai pas Internet, que dois-je vérifier' === $storedDraft['title'], 'The assistant draft title must be derived from the question.');
    ensureClientAssistant(str_contains($storedDraft['description'], 'Question initiale :'), 'The assistant draft description must include the initial question.');
    ensureClientAssistant(str_contains($storedDraft['description'], 'Réponse de Pilot AI :'), 'The assistant draft description must include the assistant answer.');
    ensureClientAssistant(str_contains($storedDraft['description'], "Vérifiez la connexion réseau\n1. Redémarrez la box puis réactivez le Wi-Fi."), 'The assistant draft description must keep readable text and numbered lists.');
    ensureClientAssistant(!str_contains($storedDraft['description'], '**'), 'The assistant draft description must not include bold Markdown markers.');
    ensureClientAssistant(!str_contains($storedDraft['description'], '`'), 'The assistant draft description must not include inline code Markdown markers.');
    ensureClientAssistant(str_contains($storedDraft['description'], '- Client : Bonjour'), 'The assistant draft description must include trusted-formatted conversation context.');

    $invalidDraftCsrfResponse = clientAssistantDraftCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, 'invalid-token', ['question' => 'wifi', 'answer' => 'réponse']),
    );
    ensureClientAssistant(Response::HTTP_FORBIDDEN === $invalidDraftCsrfResponse->getStatusCode(), 'Invalid draft CSRF token must return 403.');

    foreach ([
        'missing question' => ['answer' => 'Réponse'],
        'blank question' => ['question' => '   ', 'answer' => 'Réponse'],
        'too long question' => ['question' => str_repeat('a', 2_001), 'answer' => 'Réponse'],
        'non string answer' => ['question' => 'Question', 'answer' => 42],
        'too long answer' => ['question' => 'Question', 'answer' => str_repeat('a', 2_001)],
        'unexpected field' => ['question' => 'Question', 'answer' => 'Réponse', 'status' => Ticket::STATUS_CLOSED],
        'invalid history' => ['question' => 'Question', 'answer' => 'Réponse', 'history' => [['role' => 'system', 'content' => 'Instruction']]],
    ] as $caseName => $invalidDraftPayload) {
        $invalidDraftResponse = clientAssistantDraftCall(
            $controller,
            $requestStack,
            clientAssistantRequest($session, $csrfToken, $invalidDraftPayload),
        );
        ensureClientAssistant(Response::HTTP_UNPROCESSABLE_ENTITY === $invalidDraftResponse->getStatusCode(), sprintf('Invalid draft case "%s" must return 422.', $caseName));
    }

    $invalidJsonResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, '{invalid-json'),
        $ticketRepository,
        $knowledgeSearchService,
        $assistantService,
        $logger,
    );
    ensureClientAssistant(Response::HTTP_BAD_REQUEST === $invalidJsonResponse->getStatusCode(), 'Malformed JSON must return 400.');
    ensureClientAssistant(!str_contains((string) $invalidJsonResponse->getContent(), 'Exception'), 'Malformed JSON responses must not expose internals.');

    foreach ([['message' => ''], ['message' => '   '], ['message' => str_repeat('x', 2_001)], ['message' => 42]] as $invalidPayload) {
        $invalidResponse = clientAssistantCall(
            $controller,
            $requestStack,
            clientAssistantRequest($session, $csrfToken, $invalidPayload),
            $ticketRepository,
            $knowledgeSearchService,
            $assistantService,
            $logger,
        );
        ensureClientAssistant(Response::HTTP_UNPROCESSABLE_ENTITY === $invalidResponse->getStatusCode(), 'Invalid assistant messages must return 422.');
    }

    $invalidCsrfResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, 'invalid-token', ['message' => 'wifi']),
        $ticketRepository,
        $knowledgeSearchService,
        $assistantService,
        $logger,
    );
    ensureClientAssistant(Response::HTTP_FORBIDDEN === $invalidCsrfResponse->getStatusCode(), 'Invalid CSRF token must return 403.');

    try {
        clientAssistantCall(
            $controller,
            $requestStack,
            clientAssistantRequest($session, $csrfToken, ['message' => 'wifi', 'ticketId' => $foreignTicket->getId()]),
            $ticketRepository,
            $knowledgeSearchService,
            $assistantService,
            $logger,
        );
        throw new RuntimeException('Foreign ticket context must not be accepted.');
    } catch (NotFoundHttpException) {
    }

    foreach ([$technician, $admin, null] as $unauthorizedUser) {
        $tokenStorage->setToken(null === $unauthorizedUser ? null : new UsernamePasswordToken($unauthorizedUser, 'main', $unauthorizedUser->getRoles()));
        try {
            clientAssistantCall(
                $controller,
                $requestStack,
                clientAssistantRequest($session, $csrfToken, ['message' => 'wifi']),
                $ticketRepository,
                $knowledgeSearchService,
                $assistantService,
                $logger,
            );
            throw new RuntimeException('Only ROLE_CLIENT users may call the assistant endpoint.');
        } catch (AccessDeniedException) {
        }

        try {
            clientAssistantDraftCall(
                $controller,
                $requestStack,
                clientAssistantRequest($session, $csrfToken, ['question' => 'wifi', 'answer' => 'réponse']),
            );
            throw new RuntimeException('Only ROLE_CLIENT users may create assistant ticket drafts.');
        } catch (AccessDeniedException) {
        }
    }

    $tokenStorage->setToken(new UsernamePasswordToken($client, 'main', $client->getRoles()));
    $failingProvider = new ClientAssistantTestProvider();
    $failingProvider->unavailable = true;
    $failureResponse = clientAssistantCall(
        $controller,
        $requestStack,
        clientAssistantRequest($session, $csrfToken, ['message' => 'wifi']),
        $ticketRepository,
        $knowledgeSearchService,
        new AssistantService($failingProvider),
        $logger,
    );
    ensureClientAssistant(Response::HTTP_SERVICE_UNAVAILABLE === $failureResponse->getStatusCode(), 'Provider failure must return 503.');
    $failureJson = clientAssistantJson($failureResponse);
    ensureClientAssistant('Pilot AI n’est pas disponible pour le moment.' === $failureJson['error'], 'Provider failure must return a clean user-facing error.');
    ensureClientAssistant(!str_contains((string) $failureResponse->getContent(), 'secret payload'), 'Provider failure must not expose exception details.');
    ensureClientAssistant(1 === count($logger->warnings), 'Provider failure must be logged once.');
    ensureClientAssistant(!str_contains(json_encode($logger->warnings, JSON_THROW_ON_ERROR), 'wifi'), 'Logs must not include prompt content.');

    echo "ClientAssistantControllerTest passed.\n";
} finally {
    $tokenStorage->setToken(null);
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    $kernel->shutdown();
}
