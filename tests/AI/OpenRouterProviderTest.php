<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\Exception\AIProviderException;
use App\AI\OpenRouterProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/** @var list<MockHttpClient> $mockHttpClients */
$mockHttpClients = [];

function responseBody(array $analysis): string
{
    return json_encode([
        'choices' => [[
            'message' => [
                'content' => json_encode($analysis, JSON_THROW_ON_ERROR),
            ],
        ]],
    ], JSON_THROW_ON_ERROR);
}

function validAnalysis(): array
{
    return [
        'summary' => 'Résumé du ticket',
        'suggestedPriority' => 'HIGH',
        'suggestedCategory' => 'Support',
        'keywords' => ['connexion', 'erreur'],
        'suggestions' => ['Vérifier les journaux'],
    ];
}

function providerWithResponse(MockResponse|callable $response): OpenRouterProvider
{
    global $mockHttpClients;

    $httpClient = new MockHttpClient($response);
    $mockHttpClients[] = $httpClient;

    return new OpenRouterProvider(
        $httpClient,
        str_repeat('x', 32),
        'apodex/apodex-1.1-mini:free',
    );
}

function totalProviderRequests(): int
{
    global $mockHttpClients;

    return array_sum(array_map(
        static fn (MockHttpClient $httpClient): int => $httpClient->getRequestsCount(),
        $mockHttpClients,
    ));
}

function expectProviderException(callable $callback, string $scenario, ?int $expectedRequests = 1): AIProviderException
{
    $requestsBefore = totalProviderRequests();

    try {
        $callback();
    } catch (AIProviderException $exception) {
        foreach (['sensitive remote body', 'sensitive transport details', 'sensitive timeout details', str_repeat('x', 32), 'Authorization'] as $forbiddenValue) {
            ensure(!str_contains($exception->getMessage(), $forbiddenValue), sprintf('Sensitive value exposed for %s.', $scenario));
        }

        if (null !== $expectedRequests) {
            ensure(
                $expectedRequests === totalProviderRequests() - $requestsBefore,
                sprintf('%s must perform exactly %d provider request(s).', $scenario, $expectedRequests),
            );
        }

        return $exception;
    }

    throw new RuntimeException(sprintf('Expected AIProviderException for %s.', $scenario));
}

function expectSingleRequestProviderException(MockResponse|callable $response, string $scenario): AIProviderException
{
    $httpClient = new MockHttpClient($response);
    $provider = new OpenRouterProvider(
        $httpClient,
        str_repeat('x', 32),
        'apodex/apodex-1.1-mini:free',
    );
    $exception = expectProviderException(
        fn () => $provider->analyze(new AIAnalysisInput('a', 'b')),
        $scenario,
        null,
    );

    ensure(1 === $httpClient->getRequestsCount(), sprintf('%s must perform exactly one provider request.', $scenario));

    return $exception;
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$capturedOptions = null;
$provider = providerWithResponse(static function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
    $capturedOptions = compact('method', 'url', 'options');

    return new MockResponse(responseBody(validAnalysis()), ['http_code' => 200]);
});
$result = $provider->analyze(new AIAnalysisInput('Titre non fiable', 'Description non fiable'));
$requestPayload = json_decode($capturedOptions['options']['body'], true, 512, JSON_THROW_ON_ERROR);

ensure('Résumé du ticket' === $result->summary, 'Summary mapping failed.');
ensure('HIGH' === $result->suggestedPriority, 'Priority mapping failed.');
ensure(['connexion', 'erreur'] === $result->keywords, 'Keywords mapping failed.');
ensure('POST' === $capturedOptions['method'], 'Unexpected HTTP method.');
ensure('https://openrouter.ai/api/v1/chat/completions' === $capturedOptions['url'], 'Unexpected endpoint.');
ensure('apodex/apodex-1.1-mini:free' === $requestPayload['model'], 'Unexpected model.');
ensure('system' === $requestPayload['messages'][0]['role'], 'System instructions must be isolated.');
ensure('user' === $requestPayload['messages'][1]['role'], 'Ticket data must be isolated as user content.');
ensure(['type' => 'json_object'] === $requestPayload['response_format'], 'JSON-object mode must be requested for free-model compatibility.');
ensure(str_contains($requestPayload['messages'][0]['content'], 'sans balises Markdown'), 'The prompt must prohibit Markdown fences.');
ensure(str_contains($requestPayload['messages'][0]['content'], 'suggestedPriority'), 'The prompt must specify the analysis contract.');
ensure(!str_contains($requestPayload['messages'][0]['content'], 'Titre non fiable'), 'Untrusted title leaked into system instructions.');
ensure([
    'title' => 'Titre non fiable',
    'description' => 'Description non fiable',
] === json_decode($requestPayload['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR), 'Untrusted ticket data is not encoded as expected.');
ensure(false === $requestPayload['provider']['allow_fallbacks'], 'Provider fallback must be disabled.');
ensure(true === $requestPayload['provider']['require_parameters'], 'Provider parameters must be required.');
ensure('deny' === $requestPayload['provider']['data_collection'], 'Data collection must be denied.');
ensure(true === $requestPayload['provider']['zdr'], 'ZDR must be required.');
ensure(!array_key_exists('models', $requestPayload), 'Model fallbacks must not be configured.');
ensure(4_096 === $requestPayload['max_tokens'], 'Output and reasoning tokens must have sufficient bounded capacity.');
ensure(15.0 === $capturedOptions['options']['timeout'], 'Timeout must be explicit.');
ensure(15.0 === $capturedOptions['options']['max_duration'], 'Maximum duration must be explicit.');

$fencedContent = "```json\n".json_encode(validAnalysis(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n```";
$fencedResponse = json_encode([
    'choices' => [['message' => ['content' => $fencedContent]]],
], JSON_THROW_ON_ERROR);
$fencedResult = providerWithResponse(new MockResponse($fencedResponse))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure('Résumé du ticket' === $fencedResult->summary, 'A JSON response wrapped in Markdown fences must be parsed.');

$unicodeBoundaries = [
    'summary' => str_repeat('é', 2_000),
    'suggestedPriority' => 'HIGH',
    'suggestedCategory' => str_repeat('é', 100),
    'keywords' => array_map(
        static fn (int $index): string => str_repeat('é', 97).sprintf('%03d', $index),
        range(1, 20),
    ),
    'suggestions' => array_map(
        static fn (int $index): string => str_repeat('é', 997).sprintf('%03d', $index),
        range(1, 10),
    ),
];
$unicodeResult = providerWithResponse(new MockResponse(responseBody($unicodeBoundaries)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure(str_repeat('é', 100) === $unicodeResult->suggestedCategory, 'Valid Unicode boundary was rejected.');
ensure($unicodeBoundaries['keywords'] === $unicodeResult->keywords, 'Valid keyword boundary or order was modified.');
ensure($unicodeBoundaries['suggestions'] === $unicodeResult->suggestions, 'Valid suggestion boundary or order was modified.');

$singleKeyword = validAnalysis();
$singleKeyword['keywords'] = ['unique'];
$singleKeywordResult = providerWithResponse(new MockResponse(responseBody($singleKeyword)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure(['unique'] === $singleKeywordResult->keywords, 'A single keyword must be accepted unchanged.');

$caseDistinctKeywords = validAnalysis();
$caseDistinctKeywords['keywords'] = ['Erreur', 'erreur'];
$caseDistinctResult = providerWithResponse(new MockResponse(responseBody($caseDistinctKeywords)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure(['Erreur', 'erreur'] === $caseDistinctResult->keywords, 'Strictly distinct keyword casing must be preserved.');

$singleSuggestion = validAnalysis();
$singleSuggestion['suggestions'] = ['A'];
$singleSuggestionResult = providerWithResponse(new MockResponse(responseBody($singleSuggestion)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure(['A'] === $singleSuggestionResult->suggestions, 'A single one-character suggestion must be accepted unchanged.');

$caseDistinctSuggestions = validAnalysis();
$caseDistinctSuggestions['suggestions'] = ['Vérifier le service', 'vérifier le service'];
$caseDistinctSuggestionsResult = providerWithResponse(new MockResponse(responseBody($caseDistinctSuggestions)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure($caseDistinctSuggestions['suggestions'] === $caseDistinctSuggestionsResult->suggestions, 'Strictly distinct suggestion casing must be preserved.');

foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $allowedPriority) {
    $analysisWithAllowedPriority = validAnalysis();
    $analysisWithAllowedPriority['suggestedPriority'] = $allowedPriority;
    $priorityResult = providerWithResponse(new MockResponse(responseBody($analysisWithAllowedPriority)))
        ->analyze(new AIAnalysisInput('a', 'b'));

    ensure($allowedPriority === $priorityResult->suggestedPriority, sprintf('Allowed priority %s was modified.', $allowedPriority));
}

foreach ([null, '', 'CRITICAL', 'high'] as $invalidPriorityValue) {
    $analysisWithInvalidPriority = validAnalysis();
    $analysisWithInvalidPriority['suggestedPriority'] = $invalidPriorityValue;

    expectProviderException(
        fn () => providerWithResponse(new MockResponse(responseBody($analysisWithInvalidPriority)))->analyze(new AIAnalysisInput('a', 'b')),
        'invalid priority',
    );
}

$extraProperty = validAnalysis();
$extraProperty['unexpected'] = true;
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($extraProperty)))->analyze(new AIAnalysisInput('a', 'b')),
    'additional property',
);

$invalidList = validAnalysis();
$invalidList['keywords'] = ['valid', 42];
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($invalidList)))->analyze(new AIAnalysisInput('a', 'b')),
    'invalid list',
);

$oversizedCategory = validAnalysis();
$oversizedCategory['suggestedCategory'] = str_repeat('é', 101);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($oversizedCategory)))->analyze(new AIAnalysisInput('a', 'b')),
    'oversized category',
);

foreach ([null, '', " \t\n", "\u{00A0}\u{2003}"] as $invalidCategory) {
    $analysisWithInvalidCategory = validAnalysis();
    $analysisWithInvalidCategory['suggestedCategory'] = $invalidCategory;

    expectProviderException(
        fn () => providerWithResponse(new MockResponse(responseBody($analysisWithInvalidCategory)))->analyze(new AIAnalysisInput('a', 'b')),
        'missing or blank category',
    );
}

$oversizedUnicodeSummary = validAnalysis();
$oversizedUnicodeSummary['summary'] = str_repeat('é', 2_001);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($oversizedUnicodeSummary)))->analyze(new AIAnalysisInput('a', 'b')),
    'oversized Unicode summary',
);

foreach ([null, '', " \t\n", "\u{00A0}\u{2003}"] as $invalidSummary) {
    $analysisWithInvalidSummary = validAnalysis();
    $analysisWithInvalidSummary['summary'] = $invalidSummary;

    expectProviderException(
        fn () => providerWithResponse(new MockResponse(responseBody($analysisWithInvalidSummary)))->analyze(new AIAnalysisInput('a', 'b')),
        'missing or blank summary',
    );
}

$tooManyKeywords = validAnalysis();
$tooManyKeywords['keywords'] = array_map(
    static fn (int $index): string => 'keyword-'.$index,
    range(1, 21),
);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($tooManyKeywords)))->analyze(new AIAnalysisInput('a', 'b')),
    'too many keywords',
);

$oversizedUnicodeKeyword = validAnalysis();
$oversizedUnicodeKeyword['keywords'] = [str_repeat('é', 101)];
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($oversizedUnicodeKeyword)))->analyze(new AIAnalysisInput('a', 'b')),
    'oversized Unicode keyword',
);

foreach ([null, [], [''], [" \t\n"], ["\u{00A0}\u{2003}"], ['duplicate', 'duplicate'], ['key' => 'value']] as $invalidKeywords) {
    $analysisWithInvalidKeywords = validAnalysis();
    $analysisWithInvalidKeywords['keywords'] = $invalidKeywords;

    expectProviderException(
        fn () => providerWithResponse(new MockResponse(responseBody($analysisWithInvalidKeywords)))->analyze(new AIAnalysisInput('a', 'b')),
        'invalid required keywords',
    );
}

$oversizedSuggestion = validAnalysis();
$oversizedSuggestion['suggestions'] = [str_repeat('é', 1_001)];
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($oversizedSuggestion)))->analyze(new AIAnalysisInput('a', 'b')),
    'oversized suggestion',
);

$tooManySuggestions = validAnalysis();
$tooManySuggestions['suggestions'] = array_map(
    static fn (int $index): string => 'Suggestion '.$index,
    range(1, 11),
);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($tooManySuggestions)))->analyze(new AIAnalysisInput('a', 'b')),
    'too many suggestions',
);

foreach ([null, [], [''], [" \t\n"], ["\u{00A0}\u{2003}"], ['duplicate', 'duplicate'], ['key' => 'value']] as $invalidSuggestions) {
    $analysisWithInvalidSuggestions = validAnalysis();
    $analysisWithInvalidSuggestions['suggestions'] = $invalidSuggestions;

    expectProviderException(
        fn () => providerWithResponse(new MockResponse(responseBody($analysisWithInvalidSuggestions)))->analyze(new AIAnalysisInput('a', 'b')),
        'invalid required suggestions',
    );
}

$nonTextualSuggestion = validAnalysis();
$nonTextualSuggestion['suggestions'] = ['valid', 42];
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($nonTextualSuggestion)))->analyze(new AIAnalysisInput('a', 'b')),
    'non-textual suggestion',
);

$missingProperty = validAnalysis();
unset($missingProperty['summary']);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($missingProperty)))->analyze(new AIAnalysisInput('a', 'b')),
    'missing property',
);

expectSingleRequestProviderException(
    new MockResponse('{invalid-json'),
    'invalid response JSON',
);

foreach ([
    'missing choices' => [],
    'empty choices' => ['choices' => []],
    'missing message' => ['choices' => [[]]],
    'missing content' => ['choices' => [['message' => []]]],
    'non-textual content' => ['choices' => [['message' => ['content' => 42]]]],
] as $scenario => $incompleteEnvelope) {
    expectSingleRequestProviderException(
        new MockResponse(json_encode($incompleteEnvelope, JSON_THROW_ON_ERROR)),
        $scenario,
    );
}

expectSingleRequestProviderException(
    new MockResponse(json_encode([
        'choices' => [['message' => ['content' => '{invalid-json']]],
    ], JSON_THROW_ON_ERROR)),
    'invalid analysis JSON',
);

foreach ([401, 429, 400, 403, 500, 502] as $statusCode) {
    $httpException = expectSingleRequestProviderException(
        new MockResponse('sensitive remote body', ['http_code' => $statusCode]),
        sprintf('HTTP %d', $statusCode),
    );
    ensure($statusCode === $httpException->getCode(), sprintf('HTTP %d must be retained as safe diagnostic metadata.', $statusCode));
}

$transportRequestCounter = new class {
    public int $count = 0;
};
$transportException = expectProviderException(
    fn () => providerWithResponse(static function () use ($transportRequestCounter): never {
        ++$transportRequestCounter->count;

        throw new class('sensitive transport details') extends RuntimeException implements TransportExceptionInterface {
        };
    })->analyze(new AIAnalysisInput('a', 'b')),
    'transport failure',
    null,
);
ensure(1 === $transportRequestCounter->count, 'A transport failure must perform exactly one provider request.');
ensure(null === $transportException->getPrevious(), 'Transport exception details must not be exposed through the exception chain.');

$requestCount = 0;
$timeoutProvider = providerWithResponse(static function () use (&$requestCount): never {
    ++$requestCount;

    throw new class('sensitive timeout details') extends RuntimeException implements TimeoutExceptionInterface {
    };
});
$timeoutException = expectProviderException(
    fn () => $timeoutProvider->analyze(new AIAnalysisInput('a', 'b')),
    'timeout',
    null,
);
ensure(1 === $requestCount, 'The application must not retry a timed-out request.');
ensure(null === $timeoutException->getPrevious(), 'Timeout details must not be exposed through the exception chain.');

expectProviderException(
    fn () => (new OpenRouterProvider(new MockHttpClient(), '', 'apodex/apodex-1.1-mini:free'))
        ->analyze(new AIAnalysisInput('a', 'b')),
    'missing API key',
    0,
);

expectProviderException(
    fn () => (new OpenRouterProvider(new MockHttpClient(), str_repeat('x', 32), ''))
        ->analyze(new AIAnalysisInput('a', 'b')),
    'missing model',
    0,
);

echo "OpenRouterProvider simulated HTTP tests: PASS\n";
