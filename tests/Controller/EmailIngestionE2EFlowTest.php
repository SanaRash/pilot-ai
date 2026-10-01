<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\Controller\Api\EmailTicketController;
use App\Entity\AIAnalysis;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use App\Repository\UserRepository;
use App\Security\EmailWebhookAuthenticator;
use App\Service\EmailIngestionUserResolver;
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const EMAIL_E2E_SECRET = 'email_e2e_secret_0123456789abcdef';

function ensureEmailE2E(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class EmailE2ETestLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function emergency(Stringable|string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(Stringable|string $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(Stringable|string $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(Stringable|string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(Stringable|string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(Stringable|string $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(Stringable|string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(Stringable|string $message, array $context = []): void { $this->log('debug', $message, $context); }
}

final class EmailE2EProvider implements AIProviderInterface
{
    public static ?AIAnalysisInput $lastInput = null;

    public function __construct(private readonly ?Throwable $failure = null)
    {
    }

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        self::$lastInput = $input;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return new AIAnalysisResult(
            'Résumé email E2E',
            Ticket::PRIORITY_HIGH,
            'Support email',
            ['email', 'connexion'],
            ['Vérifier les informations transmises par le client'],
        );
    }
}

function emailE2EPayload(array $overrides = []): array
{
    return array_replace([
        'sender' => 'client@example.test',
        'subject' => 'Connexion impossible',
        'content' => 'La connexion au service échoue.',
        'messageId' => '<message@example.test>',
    ], $overrides);
}

function emailE2ERequest(array|string $payload, ?string $authorization = 'Bearer '.EMAIL_E2E_SECRET, string $contentType = 'application/json'): Request
{
    $content = is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload;
    $server = [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
    ];

    if (null !== $authorization) {
        $server['HTTP_AUTHORIZATION'] = $authorization;
    }

    return Request::create('/api/tickets/email', 'POST', server: $server, content: $content);
}

function emailE2EDecode(Response $response, string $label): array
{
    ensureEmailE2E(str_contains((string) $response->headers->get('Content-Type'), 'json'), $label.' must return JSON.');
    $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    ensureEmailE2E(is_array($decoded), $label.' response must decode as an array.');
    ensureEmailE2E(!str_contains((string) $response->getContent(), 'trace'), $label.' must not expose a stack trace.');

    return $decoded;
}

function emailE2EController(EntityManagerInterface $entityManager, UserRepository $userRepository, string $systemEmail, ?Throwable $aiFailure = null, ?EmailE2ETestLogger $logger = null, string $secret = EMAIL_E2E_SECRET): EmailTicketController
{
    return new EmailTicketController(
        new EmailWebhookAuthenticator($secret),
        new EmailIngestionUserResolver($userRepository, $systemEmail),
        $entityManager,
        new TicketHistoryService($entityManager),
        $logger ?? new EmailE2ETestLogger(),
        new AIService(new EmailE2EProvider($aiFailure), $entityManager),
    );
}

function emailE2ESystemUser(string $email): User
{
    return (new User())
        ->setEmail($email)
        ->setFirstname('Système')
        ->setLastname('Ingestion e-mail')
        ->setRoles([])
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable())
        ->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
}

function emailE2EAssertTicketDefaults(Ticket $ticket, User $systemUser, string $title = 'Connexion impossible', string $description = 'La connexion au service échoue.'): void
{
    ensureEmailE2E($title === $ticket->getTitle(), 'Email subject must map to ticket title.');
    ensureEmailE2E($description === $ticket->getDescription(), 'Email content must map to ticket description.');
    ensureEmailE2E('EMAIL' === $ticket->getSource(), 'Ticket source must be EMAIL.');
    ensureEmailE2E(Ticket::STATUS_OPEN === $ticket->getStatus(), 'Ticket status must be OPEN.');
    ensureEmailE2E(Ticket::PRIORITY_MEDIUM === $ticket->getPriority(), 'Ticket priority must be MEDIUM.');
    ensureEmailE2E(null === $ticket->getCategory(), 'Ticket category must remain null.');
    ensureEmailE2E(null === $ticket->getAssignedTo(), 'Ticket assignment must remain null.');
    ensureEmailE2E(null === $ticket->getUpdatedAt(), 'Ticket updatedAt must remain null.');
    ensureEmailE2E($systemUser === $ticket->getCreatedBy(), 'Ticket createdBy must be the system user.');
    ensureEmailE2E($ticket->getCreatedAt() instanceof DateTimeImmutable, 'Ticket createdAt must be generated server-side.');
}

function emailE2EAssertCreatedHistory(EntityManagerInterface $entityManager, Ticket $ticket, User $systemUser): void
{
    $histories = $entityManager->getRepository(TicketHistory::class)->findBy(['ticket' => $ticket]);
    ensureEmailE2E(1 === count($histories), 'Exactly one ticket history entry must be created.');
    $history = $histories[0];
    ensureEmailE2E('TICKET_CREATED' === $history->getAction(), 'History action must be TICKET_CREATED.');
    ensureEmailE2E(null === $history->getOldValue(), 'History oldValue must be null.');
    ensureEmailE2E(null === $history->getNewValue(), 'History newValue must be null.');
    ensureEmailE2E($systemUser === $history->getChangedBy(), 'History changedBy must be the system user.');
}

function emailE2ECount(Connection $connection, string $table): int
{
    return (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$systemEmail = 'email-e2e-system-'.bin2hex(random_bytes(8)).'@pilot-ai.internal';
$_ENV['PILOTAI_EMAIL_WEBHOOK_SECRET'] = EMAIL_E2E_SECRET;
$_SERVER['PILOTAI_EMAIL_WEBHOOK_SECRET'] = EMAIL_E2E_SECRET;
putenv('PILOTAI_EMAIL_WEBHOOK_SECRET='.EMAIL_E2E_SECRET);
$_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $systemEmail;
$_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $systemEmail;
putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL='.$systemEmail);

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$container = $kernel->getContainer();
/** @var EntityManagerInterface $entityManager */
$entityManager = $container->get('doctrine')->getManager();
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var UserRepository $userRepository */
$userRepository = $entityManager->getRepository(User::class);

$connection->beginTransaction();

try {
    $systemUser = emailE2ESystemUser($systemEmail);
    $entityManager->persist($systemUser);
    $entityManager->flush();

    $logger = new EmailE2ETestLogger();
    $controller = emailE2EController($entityManager, $userRepository, $systemEmail, logger: $logger);

    $successResponse = $controller(emailE2ERequest(emailE2EPayload()));
    $successBody = emailE2EDecode($successResponse, 'successful ingestion');
    ensureEmailE2E(Response::HTTP_CREATED === $successResponse->getStatusCode(), 'Valid email payload must return 201.');
    ensureEmailE2E('EMAIL' === ($successBody['data']['source'] ?? null), 'Response source must be EMAIL.');
    ensureEmailE2E(Ticket::STATUS_OPEN === ($successBody['data']['status'] ?? null), 'Response status must be OPEN.');
    ensureEmailE2E('created' === ($successBody['data']['aiAnalysis'] ?? null), 'Successful AI analysis must be reported as created.');

    $ticket = $entityManager->find(Ticket::class, $successBody['data']['id'] ?? null);
    ensureEmailE2E($ticket instanceof Ticket, 'Created email ticket must be persisted.');
    emailE2EAssertTicketDefaults($ticket, $systemUser);
    emailE2EAssertCreatedHistory($entityManager, $ticket, $systemUser);
    ensureEmailE2E(EmailE2EProvider::$lastInput instanceof AIAnalysisInput, 'AI provider must be called with simulated provider.');
    ensureEmailE2E('Connexion impossible' === EmailE2EProvider::$lastInput->title, 'Only title must be sent to AI input.');
    ensureEmailE2E('La connexion au service échoue.' === EmailE2EProvider::$lastInput->description, 'Only description must be sent to AI input.');
    $analysis = $entityManager->getRepository(AIAnalysis::class)->findOneBy(['ticket' => $ticket]);
    ensureEmailE2E($analysis instanceof AIAnalysis, 'AIAnalysis must be created on successful simulated AI analysis.');
    emailE2EAssertTicketDefaults($ticket, $systemUser);

    $failureLogger = new EmailE2ETestLogger();
    $failureController = emailE2EController(
        $entityManager,
        $userRepository,
        $systemEmail,
        new AIProviderException('Provider unavailable.'),
        $failureLogger,
    );
    $failureResponse = $failureController(emailE2ERequest(emailE2EPayload([
        'subject' => 'Provider indisponible',
        'content' => 'Le ticket doit être conservé.',
        'messageId' => '<provider-failure@example.test>',
    ])));
    $failureBody = emailE2EDecode($failureResponse, 'AI provider failure');
    ensureEmailE2E(Response::HTTP_CREATED === $failureResponse->getStatusCode(), 'AI provider failure must keep HTTP 201.');
    ensureEmailE2E('unavailable' === ($failureBody['data']['aiAnalysis'] ?? null), 'AI provider failure must be reported as unavailable.');
    $failureTicket = $entityManager->find(Ticket::class, $failureBody['data']['id'] ?? null);
    ensureEmailE2E($failureTicket instanceof Ticket, 'Ticket must remain after AI provider failure.');
    emailE2EAssertTicketDefaults($failureTicket, $systemUser, 'Provider indisponible', 'Le ticket doit être conservé.');
    emailE2EAssertCreatedHistory($entityManager, $failureTicket, $systemUser);
    ensureEmailE2E(null === $entityManager->getRepository(AIAnalysis::class)->findOneBy(['ticket' => $failureTicket]), 'No partial AIAnalysis may be persisted after provider failure.');
    $lastLog = $failureLogger->records[array_key_last($failureLogger->records)] ?? null;
    ensureEmailE2E('warning' === ($lastLog['level'] ?? null), 'AI provider failure must be logged as warning.');
    ensureEmailE2E('email_ingestion_ai_unavailable' === ($lastLog['context']['event'] ?? null), 'AI provider failure event must be logged.');

    $duplicateMessageId = '<duplicate-message@example.test>';
    $duplicateFirst = $controller(emailE2ERequest(emailE2EPayload([
        'subject' => 'Doublon un',
        'content' => 'Premier ticket avec même messageId.',
        'messageId' => $duplicateMessageId,
    ])));
    $duplicateSecond = $controller(emailE2ERequest(emailE2EPayload([
        'subject' => 'Doublon deux',
        'content' => 'Second ticket avec même messageId.',
        'messageId' => $duplicateMessageId,
    ])));
    $duplicateFirstBody = emailE2EDecode($duplicateFirst, 'first duplicate messageId');
    $duplicateSecondBody = emailE2EDecode($duplicateSecond, 'second duplicate messageId');
    ensureEmailE2E(Response::HTTP_CREATED === $duplicateFirst->getStatusCode(), 'First duplicate messageId payload must return 201.');
    ensureEmailE2E(Response::HTTP_CREATED === $duplicateSecond->getStatusCode(), 'Second duplicate messageId payload must return 201.');
    ensureEmailE2E(($duplicateFirstBody['data']['id'] ?? null) !== ($duplicateSecondBody['data']['id'] ?? null), 'Same messageId must not deduplicate tickets in the current MVP.');

    foreach ([
        'missing bearer' => [emailE2EPayload(), null, 'application/json', Response::HTTP_UNAUTHORIZED],
        'incorrect bearer' => [emailE2EPayload(), 'Bearer wrong-secret', 'application/json', Response::HTTP_UNAUTHORIZED],
        'secret in body' => [emailE2EPayload(['secret' => EMAIL_E2E_SECRET]), null, 'application/json', Response::HTTP_UNAUTHORIZED],
        'bad content type' => [emailE2EPayload(), 'Bearer '.EMAIL_E2E_SECRET, 'text/plain', Response::HTTP_UNSUPPORTED_MEDIA_TYPE],
        'invalid json' => ['{invalid-json', 'Bearer '.EMAIL_E2E_SECRET, 'application/json', Response::HTTP_BAD_REQUEST],
        'missing subject' => [array_diff_key(emailE2EPayload(), ['subject' => true]), 'Bearer '.EMAIL_E2E_SECRET, 'application/json', Response::HTTP_UNPROCESSABLE_ENTITY],
        'invalid sender' => [emailE2EPayload(['sender' => 'not-an-email']), 'Bearer '.EMAIL_E2E_SECRET, 'application/json', Response::HTTP_UNPROCESSABLE_ENTITY],
        'unknown property' => [emailE2EPayload(['status' => 'CLOSED']), 'Bearer '.EMAIL_E2E_SECRET, 'application/json', Response::HTTP_UNPROCESSABLE_ENTITY],
    ] as $label => [$payload, $authorization, $contentType, $expectedStatus]) {
        $response = $controller(emailE2ERequest($payload, $authorization, $contentType));
        ensureEmailE2E($expectedStatus === $response->getStatusCode(), sprintf('%s must return HTTP %d.', $label, $expectedStatus));
        emailE2EDecode($response, $label);
        ensureEmailE2E(!str_contains((string) $response->getContent(), EMAIL_E2E_SECRET), $label.' must not expose the Bearer secret.');
    }

    $querySecretRequest = Request::create(
        '/api/tickets/email?secret='.urlencode(EMAIL_E2E_SECRET),
        'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        content: json_encode(emailE2EPayload(), JSON_THROW_ON_ERROR),
    );
    $querySecretResponse = $controller($querySecretRequest);
    ensureEmailE2E(Response::HTTP_UNAUTHORIZED === $querySecretResponse->getStatusCode(), 'Secret in query string must not authenticate.');
    ensureEmailE2E(!str_contains((string) $querySecretResponse->getContent(), EMAIL_E2E_SECRET), 'Query-string secret must not be exposed.');

    $unconfiguredController = emailE2EController($entityManager, $userRepository, $systemEmail, logger: new EmailE2ETestLogger(), secret: '');
    $unconfiguredResponse = $unconfiguredController(emailE2ERequest(emailE2EPayload()));
    $unconfiguredBody = emailE2EDecode($unconfiguredResponse, 'unconfigured bearer secret');
    ensureEmailE2E(Response::HTTP_SERVICE_UNAVAILABLE === $unconfiguredResponse->getStatusCode(), 'Missing server Bearer secret must return 503.');
    ensureEmailE2E('email_authentication_not_configured' === ($unconfiguredBody['error']['code'] ?? null), 'Missing server Bearer secret must use stable error code.');

    $missingUserController = emailE2EController($entityManager, $userRepository, 'missing-'.$systemEmail, logger: new EmailE2ETestLogger());
    $ticketCountBeforeMissingUser = emailE2ECount($connection, 'ticket');
    $missingUserResponse = $missingUserController(emailE2ERequest(emailE2EPayload(['subject' => 'Compte système absent'])));
    $missingUserBody = emailE2EDecode($missingUserResponse, 'missing system user');
    ensureEmailE2E(Response::HTTP_SERVICE_UNAVAILABLE === $missingUserResponse->getStatusCode(), 'Missing system user must return 503.');
    ensureEmailE2E('email_ingestion_system_user_unavailable' === ($missingUserBody['error']['code'] ?? null), 'Missing system user must use stable error code.');
    ensureEmailE2E($ticketCountBeforeMissingUser === emailE2ECount($connection, 'ticket'), 'Missing system user must not create a ticket.');

    $workflowPath = dirname(__DIR__, 2).'/n8n/workflows/email-reception-imap.json';
    $workflow = json_decode((string) file_get_contents($workflowPath), true, 512, JSON_THROW_ON_ERROR);
    ensureEmailE2E(is_array($workflow), 'n8n workflow JSON must be importable.');
    $serializedWorkflow = json_encode($workflow, JSON_THROW_ON_ERROR);
    ensureEmailE2E(str_contains($serializedWorkflow, 'n8n-nodes-base.emailReadImap'), 'n8n workflow must contain the IMAP trigger.');
    ensureEmailE2E(str_contains($serializedWorkflow, '"name":"sender"'), 'n8n workflow must extract sender.');
    ensureEmailE2E(str_contains($serializedWorkflow, '"name":"subject"'), 'n8n workflow must extract subject.');
    ensureEmailE2E(str_contains($serializedWorkflow, '"name":"content"'), 'n8n workflow must extract content.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'credentials'), 'n8n workflow must not export credentials.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'Bearer'), 'n8n workflow must not contain a Bearer secret.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'OPENROUTER'), 'n8n workflow must not contain an OpenRouter key.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'n8n-nodes-base.httpRequest'), 'n8n workflow must not contain HTTP Request in the current MVP.');
    ensureEmailE2E(!str_contains($serializedWorkflow, '/api/tickets/email'), 'n8n workflow must not call the email API in the current MVP.');

    $envContent = (string) file_get_contents(dirname(__DIR__, 2).'/.env');
    ensureEmailE2E((bool) preg_match('/^PILOTAI_EMAIL_WEBHOOK_SECRET=$/m', $envContent), '.env must not contain a real email webhook secret.');
    ensureEmailE2E((bool) preg_match('/^OPENROUTER_API_KEY=$/m', $envContent), '.env must not contain a real OpenRouter API key.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'password'), 'n8n workflow must not contain an IMAP password field.');
    ensureEmailE2E(!str_contains($serializedWorkflow, 'apiKey'), 'n8n workflow must not contain API keys.');

    echo "Email ingestion E2E flow tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
