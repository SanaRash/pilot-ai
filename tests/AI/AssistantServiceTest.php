<?php

declare(strict_types=1);

use App\AI\Assistant\AssistantException;
use App\AI\Assistant\AssistantHistoryMessage;
use App\AI\Assistant\AssistantInput;
use App\AI\Assistant\AssistantProviderInterface;
use App\AI\Assistant\AssistantReply;
use App\AI\Assistant\AssistantService;
use App\Entity\Category;
use App\Entity\KnowledgeArticle;
use App\Entity\Ticket;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureAssistantService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class AssistantServiceTestProvider implements AssistantProviderInterface
{
    public ?AssistantInput $capturedInput = null;

    public function __construct(
        private readonly string $answer = 'Essayez de redémarrer le routeur.',
        private readonly bool $needsTechnician = false,
    )
    {
    }

    public function generateReply(AssistantInput $input): AssistantReply
    {
        $this->capturedInput = $input;

        return new AssistantReply($this->answer, $this->needsTechnician);
    }
}

$category = (new Category())->setName('Réseau');
$ticket = (new Ticket())
    ->setTitle('Connexion Wi-Fi')
    ->setDescription('Connexion instable')
    ->setStatus(Ticket::STATUS_OPEN)
    ->setPriority(Ticket::PRIORITY_HIGH)
    ->setSource('APP')
    ->setCreatedAt(new DateTimeImmutable())
    ->setCategory($category);
$articles = [];
for ($index = 0; $index < 4; ++$index) {
    $articles[] = (new KnowledgeArticle('Article '.$index, 'Contenu '.$index))
        ->setIsActive(true)
        ->setIsClientSafe(true);
}
$articles[] = (new KnowledgeArticle('Article interne', 'Contenu interne'))
    ->setIsActive(true)
    ->setIsClientSafe(false);
$articles[] = (new KnowledgeArticle('Article inactif', 'Contenu archivé'))
    ->setIsActive(false)
    ->setIsClientSafe(true);

$provider = new AssistantServiceTestProvider();
$history = [
    new AssistantHistoryMessage(AssistantHistoryMessage::ROLE_USER, 'Bonjour'),
    new AssistantHistoryMessage(AssistantHistoryMessage::ROLE_ASSISTANT, 'Bonjour, comment puis-je aider ?'),
];
$reply = (new AssistantService($provider))->reply('Le Wi-Fi se coupe.', $articles, $ticket, $history);

ensureAssistantService('Essayez de redémarrer le routeur.' === $reply->answer, 'The assistant response must be returned.');
ensureAssistantService(false === $reply->needsTechnician, 'The provider needsTechnician flag must be kept when knowledge exists.');
ensureAssistantService($provider->capturedInput instanceof AssistantInput, 'The provider must receive a dedicated assistant DTO.');
ensureAssistantService('Connexion Wi-Fi' === $provider->capturedInput->ticketTitle, 'Optional ticket title must be passed.');
ensureAssistantService('Connexion instable' === $provider->capturedInput->ticketDescription, 'Optional ticket description must be passed.');
ensureAssistantService('Le Wi-Fi se coupe.' === $provider->capturedInput->clientMessage, 'The latest client message must be passed.');
ensureAssistantService('Réseau' === $provider->capturedInput->categoryName, 'Optional ticket category must be passed.');
ensureAssistantService($history === $provider->capturedInput->history, 'Short conversation history must be passed to the provider.');
ensureAssistantService(3 === count($provider->capturedInput->knowledgeArticles), 'At most three articles may be passed to the assistant.');
ensureAssistantService(
    array_reduce(
        $provider->capturedInput->knowledgeArticles,
        static fn (bool $safe, KnowledgeArticle $article): bool => $safe && $article->isActive() && $article->isClientSafe(),
        true,
    ),
    'Inactive or non-client-safe articles must never reach the assistant.',
);
ensureAssistantService(Ticket::STATUS_OPEN === $ticket->getStatus(), 'The assistant must not change ticket status.');
ensureAssistantService(Ticket::PRIORITY_HIGH === $ticket->getPriority(), 'The assistant must not change ticket priority.');
ensureAssistantService($category === $ticket->getCategory(), 'The assistant must not change ticket category.');
ensureAssistantService(null === $ticket->getAssignedTo(), 'The assistant must not assign the ticket.');

$globalProvider = new AssistantServiceTestProvider('Réponse globale.', true);
$globalReply = (new AssistantService($globalProvider))->reply('Question générale', [$articles[0]], null);
ensureAssistantService('Réponse globale.' === $globalReply->answer, 'The global assistant response must be returned.');
ensureAssistantService(true === $globalReply->needsTechnician, 'The provider needsTechnician flag must be kept when knowledge exists.');
ensureAssistantService($globalProvider->capturedInput instanceof AssistantInput, 'The global provider input must be captured.');
ensureAssistantService(null === $globalProvider->capturedInput->ticketTitle, 'Global assistant must work without ticket title.');
ensureAssistantService(null === $globalProvider->capturedInput->ticketDescription, 'Global assistant must work without ticket description.');
ensureAssistantService(null === $globalProvider->capturedInput->categoryName, 'Global assistant must work without category.');

$noKnowledgeProvider = new AssistantServiceTestProvider('Je ne sais pas.', false);
$noKnowledgeReply = (new AssistantService($noKnowledgeProvider))->reply('Question sans connaissance', [], null);
ensureAssistantService(true === $noKnowledgeReply->needsTechnician, 'No retained knowledge article must force needsTechnician=true.');

foreach (['', '   ', str_repeat('a', 2_001)] as $invalidReply) {
    try {
        (new AssistantService(new AssistantServiceTestProvider($invalidReply)))->reply('Question', [], null);
        throw new RuntimeException('An invalid provider reply must throw AssistantException.');
    } catch (AssistantException) {
    }
}

echo "AssistantServiceTest passed.\n";
