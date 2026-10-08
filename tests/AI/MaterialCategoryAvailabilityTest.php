<?php

declare(strict_types=1);

use App\AI\AIAnalysisInput;
use App\AI\AIAnalysisResult;
use App\AI\AIProviderInterface;
use App\AI\AIService;
use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use App\Repository\CategoryRepository;
use App\Service\TicketCategorySuggestionService;
use App\Service\TicketHistoryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureMaterialCategory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class MaterialCategoryProvider implements AIProviderInterface
{
    public int $callCount = 0;
    public ?AIAnalysisInput $receivedInput = null;

    public function analyze(AIAnalysisInput $input): AIAnalysisResult
    {
        ++$this->callCount;
        $this->receivedInput = $input;

        return new AIAnalysisResult(
            summary: 'Écran noir au démarrage malgré les vérifications de base.',
            suggestedPriority: Ticket::PRIORITY_HIGH,
            suggestedCategory: 'Matériel',
            keywords: ['écran noir', 'démarrage', 'matériel'],
            suggestions: ['Vérifier l’écran, le câble vidéo et un autre port vidéo.'],
        );
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
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);

$connection->beginTransaction();

try {
    $allowedCategories = $categoryRepository->findAllNamesForAI();

    ensureMaterialCategory(
        ['Impression', 'Logiciel', 'Matériel', 'Réseau', 'Téléphonie'] === $allowedCategories,
        'Allowed AI categories must include Matériel in deterministic order.',
    );

    $materialCategories = $categoryRepository->findBy(['name' => 'Matériel']);
    ensureMaterialCategory(1 === count($materialCategories), 'Exactly one Matériel category must exist.');
    $materialCategory = $materialCategories[0];

    $client = (new User())
        ->setEmail('material-category-client@example.test')
        ->setRoles(['ROLE_CLIENT'])
        ->setPassword(password_hash('TestPassword123!', PASSWORD_DEFAULT))
        ->setFirstname('Client')
        ->setLastname('Matériel')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2099-01-01 09:00:00'));
    $ticket = (new Ticket())
        ->setTitle('Écran noir au démarrage')
        ->setDescription('Bonjour, mon écran reste noir au démarrage malgré la vérification du câble vidéo.')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('EMAIL')
        ->setCreatedAt(new DateTimeImmutable('2099-01-02 10:00:00'))
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setAssignedTo(null)
        ->setCategory(null);

    $entityManager->persist($client);
    $entityManager->persist($ticket);
    $entityManager->flush();

    $categoryCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM category');
    $provider = new MaterialCategoryProvider();
    $aiService = new AIService($provider, $entityManager, $categoryRepository);
    $categorySuggestionService = new TicketCategorySuggestionService(
        $categoryRepository,
        $entityManager,
        new TicketHistoryService($entityManager),
    );

    $result = $aiService->analyzeTicket($ticket);
    ensureMaterialCategory(1 === $provider->callCount, 'Material ticket analysis must call the provider exactly once.');
    ensureMaterialCategory(
        in_array('Matériel', $provider->receivedInput?->allowedCategories ?? [], true),
        'Matériel must be sent to the provider as an allowed category.',
    );
    ensureMaterialCategory('Matériel' === $result->suggestedCategory, 'Material ticket must receive the Matériel suggestion.');

    $analysis = $entityManager->getRepository(AIAnalysis::class)->findOneBy(['ticket' => $ticket]);
    ensureMaterialCategory($analysis instanceof AIAnalysis, 'Material ticket analysis must be persisted.');
    ensureMaterialCategory('Matériel' === $analysis->getSuggestedCategory(), 'Persisted analysis must keep Matériel.');

    $applied = $categorySuggestionService->applySuggestion($ticket, $result->suggestedCategory);
    ensureMaterialCategory($applied, 'Matériel suggestion must be applied to the uncategorized ticket.');
    ensureMaterialCategory($materialCategory->getId() === $ticket->getCategory()?->getId(), 'Ticket category must become Matériel.');
    ensureMaterialCategory($categoryCountBefore === (int) $connection->fetchOne('SELECT COUNT(*) FROM category'), 'AI categorization must not create a Category.');

    ensureMaterialCategory(
        1 === (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ? AND action = ? AND changed_by_id IS NULL AND old_value IS NULL AND new_value = ?',
            [$ticket->getId(), 'CATEGORY_AUTO_ASSIGNED', (string) $materialCategory->getId()],
        ),
        'Automatic Matériel category assignment must be recorded as system history.',
    );

    echo "Material category availability tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
