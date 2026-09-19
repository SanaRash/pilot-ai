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
    return new OpenRouterProvider(
        new MockHttpClient($response),
        str_repeat('x', 32),
        'mistralai/mistral-small-2603',
    );
}

function expectProviderException(callable $callback, string $scenario): AIProviderException
{
    try {
        $callback();
    } catch (AIProviderException $exception) {
        foreach (['sensitive remote body', 'sensitive transport details', 'sensitive timeout details', str_repeat('x', 32)] as $forbiddenValue) {
            ensure(!str_contains($exception->getMessage(), $forbiddenValue), sprintf('Sensitive value exposed for %s.', $scenario));
        }

        return $exception;
    }

    throw new RuntimeException(sprintf('Expected AIProviderException for %s.', $scenario));
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
ensure('mistralai/mistral-small-2603' === $requestPayload['model'], 'Unexpected model.');
ensure('system' === $requestPayload['messages'][0]['role'], 'System instructions must be isolated.');
ensure('user' === $requestPayload['messages'][1]['role'], 'Ticket data must be isolated as user content.');
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
ensure(true === $requestPayload['response_format']['json_schema']['strict'], 'JSON schema must be strict.');
ensure(false === $requestPayload['response_format']['json_schema']['schema']['additionalProperties'], 'Additional properties must be forbidden.');
ensure('string' === $requestPayload['response_format']['json_schema']['schema']['properties']['summary']['type'], 'Summary must be required and non-nullable.');
ensure('string' === $requestPayload['response_format']['json_schema']['schema']['properties']['suggestedPriority']['type'], 'Suggested priority must be required and non-nullable.');
ensure(['LOW', 'MEDIUM', 'HIGH', 'URGENT'] === $requestPayload['response_format']['json_schema']['schema']['properties']['suggestedPriority']['enum'], 'Suggested priority enum is invalid.');
ensure(1_000 === $requestPayload['max_tokens'], 'Output tokens must be bounded.');
ensure(2_000 === $requestPayload['response_format']['json_schema']['schema']['properties']['summary']['maxLength'], 'Summary length must be bounded.');
ensure(100 === $requestPayload['response_format']['json_schema']['schema']['properties']['suggestedCategory']['maxLength'], 'Category length must match persistence constraints.');
ensure(20 === $requestPayload['response_format']['json_schema']['schema']['properties']['keywords']['maxItems'], 'Keyword count must be bounded.');
ensure(10 === $requestPayload['response_format']['json_schema']['schema']['properties']['suggestions']['maxItems'], 'Suggestion count must be bounded.');
ensure(15.0 === $capturedOptions['options']['timeout'], 'Timeout must be explicit.');
ensure(15.0 === $capturedOptions['options']['max_duration'], 'Maximum duration must be explicit.');

$unicodeBoundaries = [
    'summary' => str_repeat('é', 2_000),
    'suggestedPriority' => 'HIGH',
    'suggestedCategory' => str_repeat('é', 100),
    'keywords' => array_fill(0, 20, str_repeat('é', 100)),
    'suggestions' => array_fill(0, 10, str_repeat('é', 1_000)),
];
$unicodeResult = providerWithResponse(new MockResponse(responseBody($unicodeBoundaries)))
    ->analyze(new AIAnalysisInput('a', 'b'));
ensure(str_repeat('é', 100) === $unicodeResult->suggestedCategory, 'Valid Unicode boundary was rejected.');

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
$tooManyKeywords['keywords'] = array_fill(0, 21, 'keyword');
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

$oversizedSuggestion = validAnalysis();
$oversizedSuggestion['suggestions'] = [str_repeat('é', 1_001)];
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($oversizedSuggestion)))->analyze(new AIAnalysisInput('a', 'b')),
    'oversized suggestion',
);

$missingProperty = validAnalysis();
unset($missingProperty['summary']);
expectProviderException(
    fn () => providerWithResponse(new MockResponse(responseBody($missingProperty)))->analyze(new AIAnalysisInput('a', 'b')),
    'missing property',
);

expectProviderException(
    fn () => providerWithResponse(new MockResponse('{invalid-json'))->analyze(new AIAnalysisInput('a', 'b')),
    'invalid response JSON',
);

expectProviderException(
    fn () => providerWithResponse(new MockResponse(json_encode([
        'choices' => [['message' => ['content' => '{invalid-json']]],
    ], JSON_THROW_ON_ERROR)))->analyze(new AIAnalysisInput('a', 'b')),
    'invalid analysis JSON',
);

foreach ([401, 429, 500] as $statusCode) {
    expectProviderException(
        fn () => providerWithResponse(new MockResponse('sensitive remote body', ['http_code' => $statusCode]))
            ->analyze(new AIAnalysisInput('a', 'b')),
        sprintf('HTTP %d', $statusCode),
    );
}

$transportException = expectProviderException(
    fn () => providerWithResponse(static function (): never {
        throw new class('sensitive transport details') extends RuntimeException implements TransportExceptionInterface {
        };
    })->analyze(new AIAnalysisInput('a', 'b')),
    'transport failure',
);
ensure(null === $transportException->getPrevious(), 'Transport exception details must not be exposed through the exception chain.');

$requestCount = 0;
$timeoutProvider = providerWithResponse(static function () use (&$requestCount): never {
    ++$requestCount;

    throw new class('sensitive timeout details') extends RuntimeException implements TimeoutExceptionInterface {
    };
});
expectProviderException(
    fn () => $timeoutProvider->analyze(new AIAnalysisInput('a', 'b')),
    'timeout',
);
ensure(1 === $requestCount, 'The application must not retry a timed-out request.');

expectProviderException(
    fn () => (new OpenRouterProvider(new MockHttpClient(), '', 'mistralai/mistral-small-2603'))
        ->analyze(new AIAnalysisInput('a', 'b')),
    'missing API key',
);

expectProviderException(
    fn () => (new OpenRouterProvider(new MockHttpClient(), str_repeat('x', 32), ''))
        ->analyze(new AIAnalysisInput('a', 'b')),
    'missing model',
);

echo "OpenRouterProvider simulated HTTP tests: PASS\n";
