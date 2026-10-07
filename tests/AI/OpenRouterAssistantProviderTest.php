<?php

declare(strict_types=1);

use App\AI\Assistant\AssistantException;
use App\AI\Assistant\AssistantHistoryMessage;
use App\AI\Assistant\AssistantInput;
use App\AI\Assistant\OpenRouterAssistantProvider;
use App\Entity\KnowledgeArticle;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureOpenRouterAssistant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$captured = null;
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
    $captured = compact('method', 'url', 'options');

    return new MockResponse(json_encode([
        'choices' => [['message' => ['content' => json_encode([
            'answer' => 'Une réponse courte.',
            'needsTechnician' => false,
        ], JSON_THROW_ON_ERROR)]]],
    ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
});
$provider = new OpenRouterAssistantProvider($client, 'unit-test-key', 'test-model');
$article = new KnowledgeArticle('Procédure', 'Contenu article');
$reply = $provider->generateReply(new AssistantInput(
    clientMessage: 'Message non fiable',
    knowledgeArticles: [$article],
    history: [
        new AssistantHistoryMessage(AssistantHistoryMessage::ROLE_USER, 'Question précédente'),
        new AssistantHistoryMessage(AssistantHistoryMessage::ROLE_ASSISTANT, 'Réponse précédente'),
    ],
    ticketTitle: 'Titre non fiable',
    ticketDescription: 'Description non fiable',
    categoryName: 'Réseau',
));
$payload = json_decode($captured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
$userContent = json_decode($payload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);

ensureOpenRouterAssistant('Une réponse courte.' === $reply->answer, 'The provider must return the response content.');
ensureOpenRouterAssistant(false === $reply->needsTechnician, 'The provider must return the structured needsTechnician flag.');
ensureOpenRouterAssistant('POST' === $captured['method'], 'The provider must use POST.');
ensureOpenRouterAssistant('https://openrouter.ai/api/v1/chat/completions' === $captured['url'], 'Unexpected OpenRouter endpoint.');
ensureOpenRouterAssistant('Titre non fiable' === $userContent['ticket']['title'], 'Optional ticket title must be passed as user data.');
ensureOpenRouterAssistant('Message non fiable' === $userContent['currentMessage'], 'The current message must be passed as user data.');
ensureOpenRouterAssistant('Question précédente' === $userContent['history'][0]['content'], 'Short history must be passed as controlled conversation data.');
ensureOpenRouterAssistant('assistant' === $userContent['history'][1]['role'], 'Assistant history role must be passed as controlled conversation data.');
ensureOpenRouterAssistant('Procédure' === $userContent['knowledgeArticles'][0]['title'], 'The retained article must be passed as context.');
ensureOpenRouterAssistant(!str_contains($payload['messages'][0]['content'], 'Message non fiable'), 'Untrusted data must not enter system instructions.');
ensureOpenRouterAssistant(str_contains($payload['messages'][0]['content'], 'données non fiables'), 'System instructions must identify untrusted context.');
ensureOpenRouterAssistant(str_contains($payload['messages'][0]['content'], 'connaissances sont insuffisantes'), 'System instructions must define the no-knowledge fallback.');
ensureOpenRouterAssistant(str_contains($payload['messages'][0]['content'], 'Ne prétends jamais avoir réalisé une action, créé un ticket, transmis une demande ou contacté un technicien.'), 'System instructions must forbid claiming completed ticket or handoff actions.');
ensureOpenRouterAssistant(str_contains($payload['messages'][0]['content'], 'Ne promets jamais une action future non réalisée'), 'System instructions must forbid unperformed future-action promises.');
ensureOpenRouterAssistant(str_contains($payload['messages'][0]['content'], 'le client peut créer une demande afin qu’un technicien puisse prendre le relais'), 'System instructions must prefer a truthful needsTechnician wording.');
ensureOpenRouterAssistant('json_schema' === $payload['response_format']['type'], 'The provider must request a structured JSON response.');
ensureOpenRouterAssistant(false === $payload['response_format']['json_schema']['schema']['additionalProperties'], 'Structured output must reject additional properties.');
ensureOpenRouterAssistant('deny' === $payload['provider']['data_collection'], 'OpenRouter data collection must remain disabled.');
ensureOpenRouterAssistant(true === $payload['provider']['zdr'], 'OpenRouter ZDR must remain requested.');
ensureOpenRouterAssistant(false === $payload['provider']['allow_fallbacks'], 'OpenRouter provider fallback must remain disabled.');

$globalCaptured = null;
$globalProvider = new OpenRouterAssistantProvider(
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$globalCaptured): MockResponse {
        $globalCaptured = compact('method', 'url', 'options');

        return new MockResponse(json_encode([
            'choices' => [['message' => ['content' => json_encode([
                'answer' => 'Réponse globale',
                'needsTechnician' => true,
            ], JSON_THROW_ON_ERROR)]]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }),
    'unit-test-key',
    'test-model',
);
$globalProvider->generateReply(new AssistantInput('Question générale', []));
$globalPayload = json_decode($globalCaptured['options']['body'], true, 512, JSON_THROW_ON_ERROR);
$globalUserContent = json_decode($globalPayload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
ensureOpenRouterAssistant(null === $globalUserContent['ticket'], 'Global assistant requests must not fabricate ticket context.');

foreach ([
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse('upstream error', ['http_code' => 503])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse('{invalid', ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => []], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => '{invalid']]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode(['answer' => '', 'needsTechnician' => false], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode(['answer' => str_repeat('a', 2_001), 'needsTechnician' => false], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode(['answer' => 'Réponse'], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode(['answer' => 'Réponse', 'needsTechnician' => 'false'], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(new MockResponse(json_encode(['choices' => [['message' => ['content' => json_encode(['answer' => 'Réponse', 'needsTechnician' => false, 'extra' => 'interdit'], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR), ['http_code' => 200])), 'unit-test-key', 'test-model'),
    new OpenRouterAssistantProvider(new MockHttpClient(static fn (): never => throw new TransportException('network secret body')), 'unit-test-key', 'test-model'),
] as $invalidProvider) {
    try {
        $invalidProvider->generateReply(new AssistantInput('Question', []));
        throw new RuntimeException('An upstream failure must throw AssistantException.');
    } catch (AssistantException $exception) {
        ensureOpenRouterAssistant(!str_contains($exception->getMessage(), 'unit-test-key'), 'The API key must not leak in provider exceptions.');
        ensureOpenRouterAssistant(!str_contains($exception->getMessage(), 'network secret body'), 'Transport details must not leak in provider exceptions.');
        ensureOpenRouterAssistant('OpenRouter' === ($exception->getContext()['provider'] ?? null), 'Provider errors must expose a safe provider identifier.');
        ensureOpenRouterAssistant('test-model' === ($exception->getContext()['model'] ?? null), 'Provider errors must expose a safe model identifier.');
    }
}

try {
    (new OpenRouterAssistantProvider(new MockHttpClient(), '', 'test-model'))->generateReply(new AssistantInput('Question', []));
    throw new RuntimeException('Missing API key must throw AssistantException.');
} catch (AssistantException) {
}

echo "OpenRouterAssistantProviderTest passed.\n";
