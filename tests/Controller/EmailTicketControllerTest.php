<?php

declare(strict_types=1);

use App\Controller\Api\EmailTicketController;
use App\Entity\AIAnalysis;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Service\EmailIngestionUserResolver;
use App\Service\TicketHistoryService;
use App\Kernel;
use App\Repository\UserRepository;
use App\Security\EmailWebhookAuthenticator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const TEST_EMAIL_WEBHOOK_SECRET = 'test_email_webhook_secret_0123456789abcdef';

final class EmailTestAIProvider implements AIProviderInterface
{
    public static ?\App\AI\AIAnalysisInput $receivedInput = null;

    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly ?AIAnalysisResult $result = null,
    ) {
    }

    public function analyze(\App\AI\AIAnalysisInput $input): AIAnalysisResult
    {
        self::$receivedInput = $input;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result ?? new AIAnalysisResult(
            'Résumé de test',
            'HIGH',
            'Support',
            ['email'],
            ['Contacter le client'],
        );
    }
}

function emailTestAIService(
    EntityManagerInterface $entityManager,
    ?\Throwable $failure = null,
    ?AIAnalysisResult $result = null,
): AIService
{
    return new AIService(new EmailTestAIProvider($failure, $result), $entityManager);
}

function ensureEmailEndpoint(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function callEmailEndpoint(
    Kernel $kernel,
    string $method,
    string $body,
    ?string $contentType = 'application/json',
    array $additionalServer = [],
    ?string $authorization = 'Bearer '.TEST_EMAIL_WEBHOOK_SECRET,
): Response
{
    $server = array_replace(['HTTP_ACCEPT' => 'application/json'], $additionalServer);

    if (null !== $contentType) {
        $server['CONTENT_TYPE'] = $contentType;
    }

    if (null !== $authorization) {
        $server['HTTP_AUTHORIZATION'] = $authorization;
    }

    return $kernel->handle(Request::create('/api/tickets/email', $method, server: $server, content: $body));
}

/** @return array<string, mixed> */
function decodedResponse(Response $response, string $scenario): array
{
    $contentType = $response->headers->get('Content-Type') ?? '';
    ensureEmailEndpoint(str_contains($contentType, 'json'), sprintf('%s must return JSON.', $scenario));

    $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    ensureEmailEndpoint(is_array($decoded), sprintf('%s returned an invalid JSON object.', $scenario));
    ensureEmailEndpoint(!str_contains($response->getContent(), 'trace'), sprintf('%s exposed a stack trace.', $scenario));

    return $decoded;
}

function validEmailPayload(array $overrides = []): array
{
    return array_replace([
        'sender' => 'client@example.test',
        'subject' => 'Connexion impossible',
        'content' => 'La connexion au service échoue.',
    ], $overrides);
}

function encodedPayload(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR);
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_ENV['PILOTAI_EMAIL_WEBHOOK_SECRET'] = TEST_EMAIL_WEBHOOK_SECRET;
$_SERVER['PILOTAI_EMAIL_WEBHOOK_SECRET'] = TEST_EMAIL_WEBHOOK_SECRET;
putenv('PILOTAI_EMAIL_WEBHOOK_SECRET='.TEST_EMAIL_WEBHOOK_SECRET);
$missingSystemEmail = 'missing-email-ingestion-'.bin2hex(random_bytes(8)).'@pilot-ai.internal';
$_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $missingSystemEmail;
$_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $missingSystemEmail;
putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL='.$missingSystemEmail);
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$connection = $kernel->getContainer()->get('doctrine')->getConnection();
ensureEmailEndpoint($connection instanceof Connection, 'Doctrine did not provide a DBAL connection.');
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
$countsBefore = [
    'ticket' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'),
    'analysis' => (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis'),
    'history' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'),
];

$configuredAuthenticator = new EmailWebhookAuthenticator(TEST_EMAIL_WEBHOOK_SECRET);
ensureEmailEndpoint($configuredAuthenticator->isConfigured(), 'The test authenticator must be configured.');
ensureEmailEndpoint($configuredAuthenticator->authenticate('Bearer '.TEST_EMAIL_WEBHOOK_SECRET), 'The exact secret must authenticate.');
ensureEmailEndpoint($configuredAuthenticator->authenticate('bearer '.TEST_EMAIL_WEBHOOK_SECRET), 'The Bearer scheme must be case-insensitive.');
ensureEmailEndpoint(!$configuredAuthenticator->authenticate('Bearer '.strtoupper(TEST_EMAIL_WEBHOOK_SECRET)), 'The secret must remain case-sensitive.');
ensureEmailEndpoint(!$configuredAuthenticator->authenticate('Bearer '.TEST_EMAIL_WEBHOOK_SECRET.'x'), 'A one-character secret difference must fail.');

$authenticationFailureBodies = [];
foreach ([
    'absent' => null,
    'empty' => '',
    'wrong scheme' => 'Basic '.TEST_EMAIL_WEBHOOK_SECRET,
    'missing token' => 'Bearer',
    'extra spaces' => 'Bearer  '.TEST_EMAIL_WEBHOOK_SECRET,
    'trailing element' => 'Bearer '.TEST_EMAIL_WEBHOOK_SECRET.' extra',
    'comma element' => 'Bearer '.TEST_EMAIL_WEBHOOK_SECRET.',other',
    'incorrect' => 'Bearer incorrect_secret_0123456789abcdef',
    'wrong case' => 'Bearer '.strtoupper(TEST_EMAIL_WEBHOOK_SECRET),
    'oversized token' => 'Bearer '.str_repeat('x', 257),
    'oversized header' => 'Bearer '.str_repeat('x', 506),
] as $scenario => $authorization) {
    $response = callEmailEndpoint(
        $kernel,
        'POST',
        encodedPayload(validEmailPayload()),
        authorization: $authorization,
    );
    ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $response->getStatusCode(), sprintf('%s credential must return 401.', $scenario));
    ensureEmailEndpoint('Bearer realm="Pilot AI email ingestion"' === $response->headers->get('WWW-Authenticate'), sprintf('%s credential has an invalid challenge.', $scenario));
    $body = decodedResponse($response, sprintf('%s credential', $scenario));
    ensureEmailEndpoint('authentication_required' === ($body['error']['code'] ?? null), sprintf('%s credential has an invalid code.', $scenario));
    ensureEmailEndpoint(!str_contains($response->getContent(), TEST_EMAIL_WEBHOOK_SECRET), sprintf('%s credential leaked the secret.', $scenario));
    $authenticationFailureBodies[] = $response->getContent();
}
ensureEmailEndpoint(1 === count(array_unique($authenticationFailureBodies)), 'Authentication failures must return identical bodies.');

$unauthenticatedMalformed = callEmailEndpoint($kernel, 'POST', '{invalid-json', authorization: null);
ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $unauthenticatedMalformed->getStatusCode(), 'Authentication must precede JSON decoding.');
$unauthenticatedContentType = callEmailEndpoint($kernel, 'POST', 'plain text', 'text/plain', authorization: null);
ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $unauthenticatedContentType->getStatusCode(), 'Authentication must precede Content-Type validation.');
$unauthenticatedOversized = callEmailEndpoint(
    $kernel,
    'POST',
    str_repeat('x', 1_000_001),
    authorization: null,
);
ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $unauthenticatedOversized->getStatusCode(), 'Authentication must precede body-size validation.');

$querySecretRequest = Request::create(
    '/api/tickets/email?secret='.urlencode(TEST_EMAIL_WEBHOOK_SECRET),
    'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: encodedPayload(validEmailPayload()),
);
$querySecretResponse = $kernel->handle($querySecretRequest);
ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $querySecretResponse->getStatusCode(), 'A query-string secret must not authenticate.');
ensureEmailEndpoint(!str_contains($querySecretResponse->getContent(), TEST_EMAIL_WEBHOOK_SECRET), 'A query-string secret was exposed.');

$bodySecretResponse = callEmailEndpoint(
    $kernel,
    'POST',
    encodedPayload(validEmailPayload(['secret' => TEST_EMAIL_WEBHOOK_SECRET])),
    authorization: null,
);
ensureEmailEndpoint(Response::HTTP_UNAUTHORIZED === $bodySecretResponse->getStatusCode(), 'A JSON-body secret must not authenticate.');
ensureEmailEndpoint(!str_contains($bodySecretResponse->getContent(), TEST_EMAIL_WEBHOOK_SECRET), 'A JSON-body secret was exposed.');

$unconfiguredController = new EmailTicketController(
    new EmailWebhookAuthenticator(''),
    new EmailIngestionUserResolver(
        $entityManager->getRepository(\App\Entity\User::class),
        'email-ingestion@pilot-ai.internal',
    ),
    $entityManager,
    new TicketHistoryService($entityManager),
    new NullLogger(),
    emailTestAIService($entityManager),
);
$unconfiguredResponse = $unconfiguredController(Request::create(
    '/api/tickets/email',
    'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.TEST_EMAIL_WEBHOOK_SECRET],
    content: encodedPayload(validEmailPayload()),
));
$unconfiguredBody = decodedResponse($unconfiguredResponse, 'unconfigured authentication');
ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $unconfiguredResponse->getStatusCode(), 'Missing server authentication configuration must return 503.');
ensureEmailEndpoint('email_authentication_not_configured' === ($unconfiguredBody['error']['code'] ?? null), 'Unexpected unconfigured-authentication error code.');
ensureEmailEndpoint(!str_contains($unconfiguredResponse->getContent(), TEST_EMAIL_WEBHOOK_SECRET), 'The unconfigured response exposed a secret.');

$validResponse = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload()));
$validBody = decodedResponse($validResponse, 'valid payload');
ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $validResponse->getStatusCode(), 'A valid payload must return 503 until ingestion is configured.');
ensureEmailEndpoint('email_ingestion_system_user_unavailable' === ($validBody['error']['code'] ?? null), 'Unexpected valid-payload error code.');

$validMessageIdResponse = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([
    'messageId' => '<message@example.test>',
])));
ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $validMessageIdResponse->getStatusCode(), 'A valid messageId must be accepted.');
decodedResponse($validMessageIdResponse, 'valid messageId');

foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
    $methodResponse = callEmailEndpoint($kernel, $method, '', authorization: null);
    ensureEmailEndpoint(Response::HTTP_METHOD_NOT_ALLOWED === $methodResponse->getStatusCode(), sprintf('%s must return 405.', $method));
    ensureEmailEndpoint('POST' === $methodResponse->headers->get('Allow'), sprintf('%s must advertise POST in the Allow header.', $method));
    decodedResponse($methodResponse, 'non-POST method');
}
$headResponse = callEmailEndpoint($kernel, 'HEAD', '', authorization: null);
ensureEmailEndpoint(Response::HTTP_METHOD_NOT_ALLOWED === $headResponse->getStatusCode(), 'HEAD must return 405.');
ensureEmailEndpoint('POST' === $headResponse->headers->get('Allow'), 'HEAD must advertise POST in the Allow header.');

foreach ([null, 'text/plain', 'application/x-www-form-urlencoded'] as $contentType) {
    $response = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload()), $contentType);
    ensureEmailEndpoint(Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $response->getStatusCode(), 'A non-JSON Content-Type must return 415.');
    $body = decodedResponse($response, 'non-JSON Content-Type');
    ensureEmailEndpoint('unsupported_media_type' === ($body['error']['code'] ?? null), 'Unexpected Content-Type error code.');
}

foreach (['application/x-json', 'application/json-patch+json'] as $unsupportedJsonType) {
    $response = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload()), $unsupportedJsonType);
    ensureEmailEndpoint(Response::HTTP_UNSUPPORTED_MEDIA_TYPE === $response->getStatusCode(), sprintf('%s must return 415.', $unsupportedJsonType));
}

$charsetResponse = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload()), 'application/json; charset=UTF-8');
ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $charsetResponse->getStatusCode(), 'JSON with a charset must be accepted.');
$caseInsensitiveContentTypeResponse = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload()), 'APPLICATION/JSON');
ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $caseInsensitiveContentTypeResponse->getStatusCode(), 'The JSON media type must be case-insensitive.');

$declaredOversizedResponse = callEmailEndpoint(
    $kernel,
    'POST',
    encodedPayload(validEmailPayload()),
    additionalServer: ['CONTENT_LENGTH' => '1000001'],
);
ensureEmailEndpoint(Response::HTTP_REQUEST_ENTITY_TOO_LARGE === $declaredOversizedResponse->getStatusCode(), 'An oversized Content-Length must return 413.');
decodedResponse($declaredOversizedResponse, 'oversized declared payload');

$actualOversizedResponse = callEmailEndpoint(
    $kernel,
    'POST',
    encodedPayload(validEmailPayload(['unknown' => str_repeat('x', 1_000_001)])),
);
ensureEmailEndpoint(Response::HTTP_REQUEST_ENTITY_TOO_LARGE === $actualOversizedResponse->getStatusCode(), 'An oversized actual body must return 413.');
decodedResponse($actualOversizedResponse, 'oversized actual payload');

$malformedResponse = callEmailEndpoint($kernel, 'POST', '{invalid-json');
ensureEmailEndpoint(Response::HTTP_BAD_REQUEST === $malformedResponse->getStatusCode(), 'Malformed JSON must return 400.');
$malformedBody = decodedResponse($malformedResponse, 'malformed JSON');
ensureEmailEndpoint('invalid_json' === ($malformedBody['error']['code'] ?? null), 'Unexpected malformed JSON error code.');
ensureEmailEndpoint(!str_contains($malformedResponse->getContent(), '{invalid-json'), 'Malformed input was reflected in the response.');

foreach ([[], ['value'], 'text', 42, true, null] as $invalidRoot) {
    $response = callEmailEndpoint($kernel, 'POST', json_encode($invalidRoot, JSON_THROW_ON_ERROR));
    ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'A non-object JSON root must return 422.');
    decodedResponse($response, 'non-object JSON root');
}

foreach (['sender', 'subject', 'content'] as $missingField) {
    $payload = validEmailPayload();
    unset($payload[$missingField]);
    $response = callEmailEndpoint($kernel, 'POST', encodedPayload($payload));
    ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), sprintf('Missing %s must return 422.', $missingField));
    $body = decodedResponse($response, 'missing field');
    ensureEmailEndpoint(isset($body['error']['fields'][$missingField]), sprintf('Missing %s error is absent.', $missingField));
}

foreach (['sender', 'subject', 'content', 'messageId'] as $field) {
    foreach ([null, 42, true, []] as $invalidType) {
        $response = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([$field => $invalidType])));
        ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), sprintf('Invalid %s type must return 422.', $field));
        decodedResponse($response, 'invalid field type');
    }
}

foreach (['sender', 'subject', 'content', 'messageId'] as $field) {
    foreach (['', " \t\n", "\u{00A0}\u{2003}"] as $blankValue) {
        $response = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([$field => $blankValue])));
        ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), sprintf('Blank %s must return 422.', $field));
        decodedResponse($response, 'blank field');
    }
}

foreach ([
    'sender' => 180,
    'subject' => 255,
    'content' => 50_000,
    'messageId' => 255,
] as $field => $maximum) {
    $boundaryValue = 'sender' === $field
        ? str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 51)
        : str_repeat('é', $maximum);
    $accepted = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([$field => $boundaryValue])));
    ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $accepted->getStatusCode(), sprintf('%s UTF-8 boundary must be accepted.', $field));

    $oversizedValue = 'sender' === $field
        ? str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 52)
        : str_repeat('é', $maximum + 1);
    $rejected = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([$field => $oversizedValue])));
    ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $rejected->getStatusCode(), sprintf('Oversized %s must return 422.', $field));
    decodedResponse($rejected, 'oversized field');
}

foreach (['not-an-email', 'missing-domain@', '@missing-local.test'] as $invalidSender) {
    $response = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload(['sender' => $invalidSender])));
    ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $response->getStatusCode(), 'An invalid sender must return 422.');
}

$extraPropertyResponse = callEmailEndpoint($kernel, 'POST', encodedPayload(validEmailPayload([
    'status' => 'CLOSED',
])));
$extraPropertyBody = decodedResponse($extraPropertyResponse, 'additional property');
ensureEmailEndpoint(Response::HTTP_UNPROCESSABLE_ENTITY === $extraPropertyResponse->getStatusCode(), 'An additional property must return 422.');
ensureEmailEndpoint(!str_contains($extraPropertyResponse->getContent(), 'CLOSED'), 'An additional property value was reflected in the response.');
ensureEmailEndpoint('validation_failed' === ($extraPropertyBody['error']['code'] ?? null), 'Unexpected validation error code.');

$countsAfter = [
    'ticket' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket'),
    'analysis' => (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis'),
    'history' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history'),
];
ensureEmailEndpoint($countsBefore === $countsAfter, 'The endpoint caused an unexpected business persistence.');

// Isolate the successful persistence path in a second kernel and transaction.
$creationEmail = 'email-ingestion-test-'.bin2hex(random_bytes(8)).'@pilot-ai.internal';
$_ENV['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $creationEmail;
$_SERVER['PILOTAI_EMAIL_SYSTEM_USER_EMAIL'] = $creationEmail;
putenv('PILOTAI_EMAIL_SYSTEM_USER_EMAIL='.$creationEmail);
$creationKernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$creationKernel->boot();
$creationEntityManager = $creationKernel->getContainer()->get('doctrine')->getManager();
ensureEmailEndpoint($creationEntityManager instanceof EntityManagerInterface, 'Doctrine manager unavailable for creation test.');
$creationConnection = $creationEntityManager->getConnection();
$creationConnection->beginTransaction();

try {
    $systemUser = (new User())
        ->setEmail($creationEmail)
        ->setFirstname('Système')
        ->setLastname('Ingestion e-mail')
        ->setRoles([])
        ->setIsActive(true)
        ->setCreatedAt(new \DateTimeImmutable())
        ->setPassword('$2y$10$92IXUNpkjO0 composed test hash');
    $creationEntityManager->persist($systemUser);
    $creationEntityManager->flush();

    /** @var UserRepository $userRepository */
    $userRepository = $creationEntityManager->getRepository(User::class);
    $creationController = new EmailTicketController(
        new EmailWebhookAuthenticator('test-secret'),
        new EmailIngestionUserResolver($userRepository, $creationEmail),
        $creationEntityManager,
        new TicketHistoryService($creationEntityManager),
        new \Psr\Log\NullLogger(),
        emailTestAIService($creationEntityManager),
    );
    $creationResponse = $creationController(Request::create(
        '/api/tickets/email',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ],
        content: encodedPayload([
            'sender' => 'Client@Example.test',
            'subject' => 'Demande de support',
            'content' => "Première ligne\n\nDeuxième paragraphe",
            'messageId' => '<message@example.test>',
        ]),
    ));
    $creationBody = decodedResponse($creationResponse, 'successful email ticket creation');
    ensureEmailEndpoint(Response::HTTP_CREATED === $creationResponse->getStatusCode(), 'Valid payload must return 201.');
    ensureEmailEndpoint('EMAIL' === ($creationBody['data']['source'] ?? null), 'Source must be EMAIL.');
    ensureEmailEndpoint('OPEN' === ($creationBody['data']['status'] ?? null), 'Status must be OPEN.');
    ensureEmailEndpoint('created' === ($creationBody['data']['aiAnalysis'] ?? null), 'AI analysis must be reported as created.');

    $ticket = $creationEntityManager->find(Ticket::class, $creationBody['data']['id'] ?? null);
    ensureEmailEndpoint($ticket instanceof Ticket, 'Created ticket was not found.');
    ensureEmailEndpoint('Demande de support' === $ticket->getTitle(), 'Subject must map to title.');
    ensureEmailEndpoint("Première ligne\n\nDeuxième paragraphe" === $ticket->getDescription(), 'Content must map to description.');
    ensureEmailEndpoint('EMAIL' === $ticket->getSource(), 'Ticket source must be EMAIL.');
    ensureEmailEndpoint('OPEN' === $ticket->getStatus(), 'Ticket status must be OPEN.');
    ensureEmailEndpoint('MEDIUM' === $ticket->getPriority(), 'Priority must be MEDIUM.');
    ensureEmailEndpoint(null === $ticket->getCategory(), 'Category must remain null.');
    ensureEmailEndpoint(null === $ticket->getAssignedTo(), 'Assigned user must remain null.');
    ensureEmailEndpoint(null === $ticket->getUpdatedAt(), 'Updated date must remain null.');
    ensureEmailEndpoint($systemUser === $ticket->getCreatedBy(), 'CreatedBy must be the system user.');
    ensureEmailEndpoint($ticket->getCreatedAt() instanceof \DateTimeImmutable, 'Created date must be server-generated.');
    ensureEmailEndpoint('Client@Example.test' !== $ticket->getCreatedBy()?->getEmail(), 'Sender must not become createdBy.');
    ensureEmailEndpoint('Demande de support' === EmailTestAIProvider::$receivedInput?->title, 'Only the ticket title must be sent to the AI.');
    ensureEmailEndpoint("Première ligne\n\nDeuxième paragraphe" === EmailTestAIProvider::$receivedInput?->description, 'Only the ticket description must be sent to the AI.');

    $history = $creationEntityManager->getRepository(TicketHistory::class)->findOneBy(['ticket' => $ticket]);
    ensureEmailEndpoint($history instanceof TicketHistory, 'Creation history is missing.');
    ensureEmailEndpoint('TICKET_CREATED' === $history->getAction(), 'Unexpected creation history action.');
    ensureEmailEndpoint(null === $history->getOldValue() && null === $history->getNewValue(), 'Creation history values must be null.');
    ensureEmailEndpoint($systemUser === $history->getChangedBy(), 'History changedBy must be the system user.');

    $systemUser->setIsActive(true);
    $creationEntityManager->flush();
    $failureController = new EmailTicketController(
        new EmailWebhookAuthenticator('test-secret'),
        new EmailIngestionUserResolver($userRepository, $creationEmail),
        $creationEntityManager,
        new TicketHistoryService($creationEntityManager),
        new \Psr\Log\NullLogger(),
        emailTestAIService($creationEntityManager, new \App\AI\Exception\AIProviderException('Provider unavailable.')),
    );
    $failureResponse = $failureController(Request::create(
        '/api/tickets/email',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ],
        content: encodedPayload([
            'sender' => 'Client@Example.test',
            'subject' => 'Provider indisponible',
            'content' => 'Le ticket doit rester conservé.',
        ]),
    ));
    $failureBody = decodedResponse($failureResponse, 'unavailable AI provider');
    ensureEmailEndpoint(Response::HTTP_CREATED === $failureResponse->getStatusCode(), 'AI provider failure must keep HTTP 201.');
    ensureEmailEndpoint('unavailable' === ($failureBody['data']['aiAnalysis'] ?? null), 'AI provider failure must be reported.');
    $failedTicket = $creationEntityManager->find(Ticket::class, $failureBody['data']['id'] ?? null);
    ensureEmailEndpoint($failedTicket instanceof Ticket, 'Ticket must remain after AI provider failure.');
    ensureEmailEndpoint(null === $creationEntityManager->getRepository(AIAnalysis::class)->findOneBy(['ticket' => $failedTicket]), 'No partial AI analysis may remain.');

    $invalidResultController = new EmailTicketController(
        new EmailWebhookAuthenticator('test-secret'),
        new EmailIngestionUserResolver($userRepository, $creationEmail),
        $creationEntityManager,
        new TicketHistoryService($creationEntityManager),
        new \Psr\Log\NullLogger(),
        emailTestAIService(
            $creationEntityManager,
            result: new AIAnalysisResult('Résumé', 'INVALID', 'Support', ['email'], ['Suggestion']),
        ),
    );
    $invalidResultResponse = $invalidResultController(Request::create(
        '/api/tickets/email',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ],
        content: encodedPayload([
            'sender' => 'Client@Example.test',
            'subject' => 'Réponse IA invalide',
            'content' => 'Le ticket doit rester conservé.',
        ]),
    ));
    $invalidResultBody = decodedResponse($invalidResultResponse, 'invalid AI result');
    ensureEmailEndpoint(Response::HTTP_CREATED === $invalidResultResponse->getStatusCode(), 'Invalid AI result must keep HTTP 201.');
    ensureEmailEndpoint('unavailable' === ($invalidResultBody['data']['aiAnalysis'] ?? null), 'Invalid AI result must be reported.');
    $invalidTicket = $creationEntityManager->find(Ticket::class, $invalidResultBody['data']['id'] ?? null);
    ensureEmailEndpoint($invalidTicket instanceof Ticket, 'Ticket must remain after invalid AI result.');
    ensureEmailEndpoint(null === $creationEntityManager->getRepository(AIAnalysis::class)->findOneBy(['ticket' => $invalidTicket]), 'Invalid AI result must not be persisted.');

    $systemUser->setIsActive(false);
    $creationEntityManager->flush();
    $ticketCountBeforeUnavailable = (int) $creationConnection->fetchOne('SELECT COUNT(*) FROM ticket');
    $unavailableResponse = $creationController(Request::create(
        '/api/tickets/email',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ],
        content: encodedPayload([
            'sender' => 'Client@Example.test',
            'subject' => 'Demande de support',
            'content' => 'Contenu',
        ]),
    ));
    $unavailableBody = decodedResponse($unavailableResponse, 'inactive system user');
    ensureEmailEndpoint(Response::HTTP_SERVICE_UNAVAILABLE === $unavailableResponse->getStatusCode(), 'Inactive system user must return 503.');
    ensureEmailEndpoint('email_ingestion_system_user_unavailable' === ($unavailableBody['error']['code'] ?? null), 'Unexpected unavailable-user error code.');
    ensureEmailEndpoint($ticketCountBeforeUnavailable === (int) $creationConnection->fetchOne('SELECT COUNT(*) FROM ticket'), 'Unavailable ingestion must not create a ticket.');
} finally {
    $creationConnection->rollBack();
    $creationKernel->shutdown();
}

echo "EmailTicketController tests: PASS\n";
