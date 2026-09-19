<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\AI\Exception\AIProviderException;
use App\AI\Exception\AIValidationException;
use App\Entity\Ticket;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class StubAIProvider implements AIProviderInterface
{
    public int $callCount = 0;
    public ?AIAnalysisInput $receivedInput = null;

    public function __construct(
        private readonly ?AIAnalysisResult $result = null,
        private readonly ?AIProviderException $exception = null,
    ) {
    }

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        ++$this->callCount;
        $this->receivedInput = $input;

        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->result ?? throw new RuntimeException('The test stub has no configured result.');
    }
}

function ensureService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function validTicket(): Ticket
{
    return (new Ticket())
        ->setTitle('Impossible de se connecter')
        ->setDescription('Une erreur apparaît après la saisie du mot de passe.')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('WEB')
        ->setCreatedAt(new DateTimeImmutable('2026-09-19 10:00:00'));
}

function validServiceResult(): AIAnalysisResult
{
    return new AIAnalysisResult(
        summary: 'Le client ne parvient pas à se connecter.',
        suggestedPriority: Ticket::PRIORITY_HIGH,
        suggestedCategory: 'Accès',
        keywords: ['connexion', 'mot de passe'],
        suggestions: ['Vérifier les journaux d’authentification.'],
    );
}

function expectValidationException(callable $callback, string $scenario): void
{
    try {
        $callback();
    } catch (AIValidationException) {
        return;
    }

    throw new RuntimeException(sprintf('Expected AIValidationException for %s.', $scenario));
}

function analyzeInvalidResult(AIAnalysisResult $result, string $scenario): void
{
    $provider = new StubAIProvider($result);
    $ticket = validTicket();
    $ticketBefore = serialize($ticket);

    expectValidationException(
        fn () => (new AIService($provider))->analyzeTicket($ticket),
        $scenario,
    );

    ensureService(1 === $provider->callCount, sprintf('Provider call count mismatch for %s.', $scenario));
    ensureService($ticketBefore === serialize($ticket), sprintf('Ticket mutated for %s.', $scenario));
}

$ticket = validTicket();
$ticketBefore = serialize($ticket);
$expectedResult = validServiceResult();
$provider = new StubAIProvider($expectedResult);
$actualResult = (new AIService($provider))->analyzeTicket($ticket);

ensureService($expectedResult === $actualResult, 'AIService must return the exact provider result.');
ensureService(1 === $provider->callCount, 'Provider must be called exactly once.');
ensureService(Ticket::PRIORITY_HIGH === $actualResult->suggestedPriority, 'Suggested priority must be returned unchanged.');
ensureService('Accès' === $actualResult->suggestedCategory, 'Suggested category must be returned unchanged.');
ensureService(['connexion', 'mot de passe'] === $actualResult->keywords, 'Keywords must be returned unchanged and in the same order.');
ensureService(['Vérifier les journaux d’authentification.'] === $actualResult->suggestions, 'Suggestions must be returned unchanged and in the same order.');
ensureService('Impossible de se connecter' === $provider->receivedInput?->title, 'Unexpected title sent to provider.');
ensureService('Une erreur apparaît après la saisie du mot de passe.' === $provider->receivedInput?->description, 'Unexpected description sent to provider.');
ensureService(Ticket::PRIORITY_MEDIUM === $ticket->getPriority(), 'Ticket priority must remain unchanged.');
ensureService(null === $ticket->getCategory(), 'Ticket category must remain unchanged.');
ensureService($ticketBefore === serialize($ticket), 'Ticket was mutated after a successful analysis.');

$validBoundaryResult = new AIAnalysisResult(
    summary: str_repeat('é', 2_000),
    suggestedPriority: Ticket::PRIORITY_HIGH,
    suggestedCategory: str_repeat('é', 100),
    keywords: array_map(
        static fn (int $index): string => str_repeat('é', 97).sprintf('%03d', $index),
        range(1, 20),
    ),
    suggestions: array_map(
        static fn (int $index): string => str_repeat('é', 997).sprintf('%03d', $index),
        range(1, 10),
    ),
);
$boundaryProvider = new StubAIProvider($validBoundaryResult);
ensureService(
    $validBoundaryResult === (new AIService($boundaryProvider))->analyzeTicket(validTicket()),
    'Valid UTF-8 boundaries must be accepted.',
);

$invalidInputTickets = [
    'null title' => (new Ticket())->setDescription('Description valide'),
    'blank title' => (new Ticket())->setTitle(" \t\n")->setDescription('Description valide'),
    'null description' => (new Ticket())->setTitle('Titre valide'),
    'blank description' => (new Ticket())->setTitle('Titre valide')->setDescription(" \t\n"),
];

foreach ($invalidInputTickets as $scenario => $invalidTicket) {
    $invalidInputProvider = new StubAIProvider(validServiceResult());
    $invalidTicketBefore = serialize($invalidTicket);

    expectValidationException(
        fn () => (new AIService($invalidInputProvider))->analyzeTicket($invalidTicket),
        $scenario,
    );

    ensureService(0 === $invalidInputProvider->callCount, sprintf('Provider called for %s.', $scenario));
    ensureService($invalidTicketBefore === serialize($invalidTicket), sprintf('Ticket mutated for %s.', $scenario));
}

foreach ([Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT] as $allowedPriority) {
    $allowedResult = new AIAnalysisResult('Résumé valide', $allowedPriority, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']);
    $allowedProvider = new StubAIProvider($allowedResult);

    ensureService(
        $allowedResult === (new AIService($allowedProvider))->analyzeTicket(validTicket()),
        sprintf('Allowed priority %s was not returned unchanged.', $allowedPriority),
    );
}

foreach ([null, '', 'CRITICAL', 'high'] as $invalidPriority) {
    analyzeInvalidResult(new AIAnalysisResult('Résumé valide', $invalidPriority, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'invalid priority');
}

analyzeInvalidResult(new AIAnalysisResult(null, Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'null summary');
analyzeInvalidResult(new AIAnalysisResult('', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'empty summary');
analyzeInvalidResult(new AIAnalysisResult(" \t\n", Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'ASCII blank summary');
analyzeInvalidResult(new AIAnalysisResult("\u{00A0}\u{2003}", Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'Unicode blank summary');
analyzeInvalidResult(new AIAnalysisResult(str_repeat('é', 2_001), Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['Suggestion valide']), 'oversized summary');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, ['mot-clé'], ['Suggestion valide']), 'null category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, '', ['mot-clé'], ['Suggestion valide']), 'empty category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, " \t\n", ['mot-clé'], ['Suggestion valide']), 'ASCII blank category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, "\u{00A0}\u{2003}", ['mot-clé'], ['Suggestion valide']), 'Unicode blank category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, str_repeat('é', 101), ['mot-clé'], ['Suggestion valide']), 'oversized category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', null, ['Suggestion valide']), 'null keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [], ['Suggestion valide']), 'empty keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [''], ['Suggestion valide']), 'empty keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [" \t\n"], ['Suggestion valide']), 'ASCII blank keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ["\u{00A0}\u{2003}"], ['Suggestion valide']), 'Unicode blank keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['duplicate', 'duplicate'], ['Suggestion valide']), 'duplicate keywords');
analyzeInvalidResult(new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    array_map(static fn (int $index): string => 'keyword-'.$index, range(1, 21)),
    ['Suggestion valide'],
), 'too many keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', [str_repeat('é', 101)], ['Suggestion valide']), 'oversized keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['valid', 42], ['Suggestion valide']), 'non-string keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['key' => 'value'], ['Suggestion valide']), 'non-list keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], null), 'null suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], []), 'empty suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['']), 'empty suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], [" \t\n"]), 'ASCII blank suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ["\u{00A0}\u{2003}"]), 'Unicode blank suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['duplicate', 'duplicate']), 'duplicate suggestions');
analyzeInvalidResult(new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    ['mot-clé'],
    array_map(static fn (int $index): string => 'Suggestion '.$index, range(1, 11)),
), 'too many suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], [str_repeat('é', 1_001)]), 'oversized suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['valid', 42]), 'non-string suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, 'Catégorie valide', ['mot-clé'], ['key' => 'value']), 'non-list suggestions');

$caseDistinctResult = new AIAnalysisResult(
    'Résumé valide',
    Ticket::PRIORITY_HIGH,
    'Catégorie valide',
    ['Erreur', 'erreur'],
    ['Vérifier le service', 'vérifier le service'],
);
$caseDistinctProvider = new StubAIProvider($caseDistinctResult);
ensureService(
    $caseDistinctResult === (new AIService($caseDistinctProvider))->analyzeTicket(validTicket()),
    'Strictly distinct keyword and suggestion casing must be accepted unchanged.',
);

$providerException = new AIProviderException('Provider unavailable');
$failingProvider = new StubAIProvider(exception: $providerException);
$failingTicket = validTicket();
$failingTicketBefore = serialize($failingTicket);

try {
    (new AIService($failingProvider))->analyzeTicket($failingTicket);
    throw new RuntimeException('Expected provider exception was not thrown.');
} catch (AIProviderException $caughtException) {
    ensureService($providerException === $caughtException, 'AIProviderException must propagate unchanged.');
}

ensureService(1 === $failingProvider->callCount, 'Provider must not be retried after an error.');
ensureService($failingTicketBefore === serialize($failingTicket), 'Ticket was mutated after a provider error.');

echo "AIService tests: PASS\n";
