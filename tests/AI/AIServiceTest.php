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
ensureService('Impossible de se connecter' === $provider->receivedInput?->title, 'Unexpected title sent to provider.');
ensureService('Une erreur apparaît après la saisie du mot de passe.' === $provider->receivedInput?->description, 'Unexpected description sent to provider.');
ensureService(Ticket::PRIORITY_MEDIUM === $ticket->getPriority(), 'Ticket priority must remain unchanged.');
ensureService($ticketBefore === serialize($ticket), 'Ticket was mutated after a successful analysis.');

$validBoundaryResult = new AIAnalysisResult(
    summary: str_repeat('é', 2_000),
    suggestedPriority: Ticket::PRIORITY_HIGH,
    suggestedCategory: str_repeat('é', 100),
    keywords: array_fill(0, 20, str_repeat('é', 100)),
    suggestions: array_fill(0, 10, str_repeat('é', 1_000)),
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
    $allowedResult = new AIAnalysisResult('Résumé valide', $allowedPriority, null, null, null);
    $allowedProvider = new StubAIProvider($allowedResult);

    ensureService(
        $allowedResult === (new AIService($allowedProvider))->analyzeTicket(validTicket()),
        sprintf('Allowed priority %s was not returned unchanged.', $allowedPriority),
    );
}

foreach ([null, '', 'CRITICAL', 'high'] as $invalidPriority) {
    analyzeInvalidResult(new AIAnalysisResult('Résumé valide', $invalidPriority, null, null, null), 'invalid priority');
}

analyzeInvalidResult(new AIAnalysisResult(null, Ticket::PRIORITY_HIGH, null, null, null), 'null summary');
analyzeInvalidResult(new AIAnalysisResult('', Ticket::PRIORITY_HIGH, null, null, null), 'empty summary');
analyzeInvalidResult(new AIAnalysisResult(" \t\n", Ticket::PRIORITY_HIGH, null, null, null), 'ASCII blank summary');
analyzeInvalidResult(new AIAnalysisResult("\u{00A0}\u{2003}", Ticket::PRIORITY_HIGH, null, null, null), 'Unicode blank summary');
analyzeInvalidResult(new AIAnalysisResult(str_repeat('é', 2_001), Ticket::PRIORITY_HIGH, null, null, null), 'oversized summary');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, str_repeat('é', 101), null, null), 'oversized category');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, array_fill(0, 21, 'keyword'), null), 'too many keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, [str_repeat('é', 101)], null), 'oversized keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, ['valid', 42], null), 'non-string keyword');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, ['key' => 'value'], null), 'non-list keywords');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, null, array_fill(0, 11, 'suggestion')), 'too many suggestions');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, null, [str_repeat('é', 1_001)]), 'oversized suggestion');
analyzeInvalidResult(new AIAnalysisResult('Résumé valide', Ticket::PRIORITY_HIGH, null, null, ['valid', 42]), 'non-string suggestion');

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
