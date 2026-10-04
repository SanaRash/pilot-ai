<?php

declare(strict_types=1);

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

function ensureCategorySuggestion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function categorySuggestionTicket(EntityManagerInterface $entityManager, User $user, string $suffix): Ticket
{
    $ticket = (new Ticket())
        ->setTitle('Ticket catégorisation '.$suffix)
        ->setDescription('Description de test.')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable())
        ->setCreatedBy($user);

    $entityManager->persist($ticket);
    $entityManager->flush();

    return $ticket;
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();
$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
ensureCategorySuggestion($entityManager instanceof EntityManagerInterface, 'Doctrine entity manager is unavailable.');
/** @var Connection $connection */
$connection = $entityManager->getConnection();
/** @var CategoryRepository $categoryRepository */
$categoryRepository = $entityManager->getRepository(Category::class);
$historyService = new TicketHistoryService($entityManager);
$service = new TicketCategorySuggestionService($categoryRepository, $entityManager, $historyService);
$connection->beginTransaction();

try {
    $user = (new User())
        ->setEmail('category-suggestion-'.bin2hex(random_bytes(6)).'@example.test')
        ->setFirstname('Test')
        ->setLastname('Category')
        ->setRoles([])
        ->setPassword('test-only-password')
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable());
    $entityManager->persist($user);

    $categorySuffix = bin2hex(random_bytes(6));
    $matchingCategories = [];
    foreach ([
        'network' => 'Réseau '.$categorySuffix,
        'support' => 'Support Technique '.$categorySuffix,
        'ambiguous-a' => 'Ambigu '.$categorySuffix,
        'ambiguous-b' => ' ambigu '.$categorySuffix,
    ] as $key => $name) {
        $category = (new Category())->setName($name);
        $entityManager->persist($category);
        $matchingCategories[$key] = $category;
    }
    $entityManager->flush();
    $categoryCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM category');

    $cases = [
        ['exact', $matchingCategories['network']->getName(), $matchingCategories['network']->getName(), true],
        ['case', mb_strtoupper((string) $matchingCategories['network']->getName(), 'UTF-8'), $matchingCategories['network']->getName(), true],
        ['outer-spaces', '  '.$matchingCategories['network']->getName().'  ', $matchingCategories['network']->getName(), true],
        ['multiple-spaces', ' support   technique '.$categorySuffix.' ', $matchingCategories['support']->getName(), true],
        ['accents-preserved', 'Reseau '.$categorySuffix, null, false],
        ['no-match', 'Catégorie inexistante', null, false],
        ['multiple-matches', 'AMBIGU '.$categorySuffix, null, false],
        ['null', null, null, false],
        ['empty', '', null, false],
        ['whitespace', " \t\n ", null, false],
    ];

    foreach ($cases as [$suffix, $suggestion, $expectedCategoryName, $expectedApplied]) {
        $ticket = categorySuggestionTicket($entityManager, $user, $suffix);
        $historyCountBefore = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?',
            [$ticket->getId()],
        );
        $applied = $service->applySuggestion($ticket, $suggestion);

        ensureCategorySuggestion($expectedApplied === $applied, sprintf('Unexpected result for case "%s".', $suffix));
        ensureCategorySuggestion(
            $expectedCategoryName === $ticket->getCategory()?->getName(),
            sprintf('Unexpected assigned category for case "%s".', $suffix),
        );

        $histories = $entityManager->getRepository(TicketHistory::class)->findBy(['ticket' => $ticket]);
        ensureCategorySuggestion(
            ($expectedApplied ? 1 : 0) === count($histories) - $historyCountBefore,
            sprintf('Unexpected history creation for case "%s".', $suffix),
        );

        if ($expectedApplied) {
            $history = $histories[array_key_last($histories)];
            ensureCategorySuggestion('CATEGORY_AUTO_ASSIGNED' === $history->getAction(), 'Automatic assignment must use its own history action.');
            ensureCategorySuggestion(null === $history->getOldValue(), 'Automatic assignment history oldValue must be null.');
            ensureCategorySuggestion((string) $ticket->getCategory()?->getId() === $history->getNewValue(), 'Automatic assignment history must store the category id.');
            ensureCategorySuggestion(null === $history->getChangedBy(), 'Automatic assignment history must have no human author.');
        }
    }

    $ticketWithHumanCategory = categorySuggestionTicket($entityManager, $user, 'preexisting');
    $ticketWithHumanCategory->setCategory($matchingCategories['support']);
    $entityManager->flush();
    $historyCountBefore = (int) $connection->fetchOne(
        'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?',
        [$ticketWithHumanCategory->getId()],
    );
    ensureCategorySuggestion(
        !$service->applySuggestion($ticketWithHumanCategory, $matchingCategories['network']->getName()),
        'A human-selected category must prevent automatic reassignment.',
    );
    ensureCategorySuggestion($matchingCategories['support']->getName() === $ticketWithHumanCategory->getCategory()?->getName(), 'The existing category must be preserved.');
    ensureCategorySuggestion(
        $historyCountBefore === (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?',
            [$ticketWithHumanCategory->getId()],
        ),
        'No automatic history may be created when a category already exists.',
    );

    ensureCategorySuggestion($categoryCountBefore === (int) $connection->fetchOne('SELECT COUNT(*) FROM category'), 'Category suggestions must never create Category entities.');

    echo "Ticket category suggestion service tests: PASS\n";
} finally {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    $kernel->shutdown();
}
