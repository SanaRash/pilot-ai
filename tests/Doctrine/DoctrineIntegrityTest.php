<?php

declare(strict_types=1);

use App\Entity\AIAnalysis;
use App\Entity\Category;
use App\Entity\Intervention;
use App\Entity\Ticket;
use App\Entity\TicketHistory;
use App\Entity\User;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function ensureDoctrineIntegrity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function doctrineIntegrityUser(string $email, array $roles, string $firstname, string $lastname): User
{
    return (new User())
        ->setEmail($email)
        ->setRoles($roles)
        ->setPassword(password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT))
        ->setFirstname($firstname)
        ->setLastname($lastname)
        ->setIsActive(true)
        ->setCreatedAt(new DateTimeImmutable('2026-09-01 09:00:00'));
}

function doctrineIntegrityTicket(User $client, ?Category $category = null, ?User $technician = null): Ticket
{
    return (new Ticket())
        ->setTitle('Ticket intégrité Doctrine')
        ->setDescription('Description intégrité Doctrine')
        ->setStatus(Ticket::STATUS_OPEN)
        ->setPriority(Ticket::PRIORITY_MEDIUM)
        ->setSource('APP')
        ->setCreatedAt(new DateTimeImmutable('2026-09-02 10:00:00'))
        ->setUpdatedAt(null)
        ->setCreatedBy($client)
        ->setCategory($category)
        ->setAssignedTo($technician);
}

function doctrineIntegrityIntervention(Ticket $ticket, User $technician): Intervention
{
    return (new Intervention())
        ->setTicket($ticket)
        ->setTechnician($technician)
        ->setContent('Intervention intégrité')
        ->setCreatedAt(new DateTimeImmutable('2026-09-03 10:00:00'));
}

function doctrineIntegrityAnalysis(Ticket $ticket): AIAnalysis
{
    return (new AIAnalysis())
        ->setTicket($ticket)
        ->setSummary('Résumé intégrité')
        ->setSuggestedPriority(Ticket::PRIORITY_HIGH)
        ->setSuggestedCategory('Support')
        ->setKeywords(['integrite'])
        ->setSuggestions(['Vérifier la contrainte'])
        ->setCreatedAt(new DateTimeImmutable('2026-09-04 10:00:00'));
}

function doctrineIntegrityHistory(Ticket $ticket, ?User $changedBy): TicketHistory
{
    return (new TicketHistory())
        ->setTicket($ticket)
        ->setAction('TICKET_CREATED')
        ->setOldValue(null)
        ->setNewValue(null)
        ->setChangedBy($changedBy)
        ->setCreatedAt(new DateTimeImmutable('2026-09-05 10:00:00'));
}

function doctrineIntegrityMappingValue(mixed $mapping, string $key): mixed
{
    if (is_array($mapping)) {
        return $mapping[$key] ?? null;
    }

    return $mapping->{$key} ?? null;
}

function doctrineIntegrityAssertAssociation(
    EntityManagerInterface $entityManager,
    string $className,
    string $fieldName,
    string $targetClassName,
    bool $nullable,
): void {
    /** @var ClassMetadata<object> $metadata */
    $metadata = $entityManager->getClassMetadata($className);
    ensureDoctrineIntegrity($metadata->hasAssociation($fieldName), sprintf('%s.%s association is missing.', $className, $fieldName));

    $mapping = $metadata->getAssociationMapping($fieldName);
    ensureDoctrineIntegrity(
        $mapping instanceof ManyToOneAssociationMapping
        || ClassMetadata::MANY_TO_ONE === doctrineIntegrityMappingValue($mapping, 'type'),
        sprintf('%s.%s must be a ManyToOne association.', $className, $fieldName),
    );
    ensureDoctrineIntegrity(
        $targetClassName === doctrineIntegrityMappingValue($mapping, 'targetEntity'),
        sprintf('%s.%s target entity mismatch.', $className, $fieldName),
    );

    $cascade = doctrineIntegrityMappingValue($mapping, 'cascade') ?? [];
    ensureDoctrineIntegrity(!in_array('remove', $cascade, true), sprintf('%s.%s must not cascade remove.', $className, $fieldName));
    ensureDoctrineIntegrity(
        true !== doctrineIntegrityMappingValue($mapping, 'orphanRemoval'),
        sprintf('%s.%s must not use orphanRemoval.', $className, $fieldName),
    );

    $joinColumns = doctrineIntegrityMappingValue($mapping, 'joinColumns');
    $joinColumn = is_array($joinColumns) ? reset($joinColumns) : null;
    ensureDoctrineIntegrity(false !== $joinColumn && null !== $joinColumn, sprintf('%s.%s join column is missing.', $className, $fieldName));
    ensureDoctrineIntegrity(
        $nullable === (bool) doctrineIntegrityMappingValue($joinColumn, 'nullable'),
        sprintf('%s.%s nullable mismatch.', $className, $fieldName),
    );
}

function doctrineIntegrityAssertNoDangerousOneToMany(EntityManagerInterface $entityManager, string $className): void
{
    /** @var ClassMetadata<object> $metadata */
    $metadata = $entityManager->getClassMetadata($className);

    foreach ($metadata->getAssociationMappings() as $fieldName => $mapping) {
        $cascade = doctrineIntegrityMappingValue($mapping, 'cascade') ?? [];
        ensureDoctrineIntegrity(
            !in_array('remove', $cascade, true),
            sprintf('%s.%s must not cascade remove.', $className, $fieldName),
        );
        ensureDoctrineIntegrity(
            true !== doctrineIntegrityMappingValue($mapping, 'orphanRemoval'),
            sprintf('%s.%s must not use orphanRemoval.', $className, $fieldName),
        );
    }
}

function doctrineIntegrityAssertForeignKey(
    Connection $connection,
    string $tableName,
    string $columnName,
    string $foreignTableName,
    string $foreignColumnName,
    bool $nullable,
): void {
    $row = $connection->fetchAssociative(
        <<<'SQL'
SELECT
    kcu.constraint_name,
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name,
    rc.delete_rule,
    columns.is_nullable
FROM information_schema.key_column_usage kcu
JOIN information_schema.constraint_column_usage ccu
    ON ccu.constraint_name = kcu.constraint_name
    AND ccu.constraint_schema = kcu.constraint_schema
JOIN information_schema.referential_constraints rc
    ON rc.constraint_name = kcu.constraint_name
    AND rc.constraint_schema = kcu.constraint_schema
JOIN information_schema.columns columns
    ON columns.table_schema = kcu.table_schema
    AND columns.table_name = kcu.table_name
    AND columns.column_name = kcu.column_name
WHERE kcu.table_schema = current_schema()
    AND kcu.table_name = :table_name
    AND kcu.column_name = :column_name
SQL,
        [
            'table_name' => $tableName,
            'column_name' => $columnName,
        ],
    );

    ensureDoctrineIntegrity(false !== $row, sprintf('Foreign key %s.%s is missing.', $tableName, $columnName));
    ensureDoctrineIntegrity($foreignTableName === $row['foreign_table_name'], sprintf('Foreign table mismatch for %s.%s.', $tableName, $columnName));
    ensureDoctrineIntegrity($foreignColumnName === $row['foreign_column_name'], sprintf('Foreign column mismatch for %s.%s.', $tableName, $columnName));
    ensureDoctrineIntegrity('CASCADE' !== $row['delete_rule'], sprintf('%s.%s must not use ON DELETE CASCADE.', $tableName, $columnName));
    ensureDoctrineIntegrity(
        ($nullable ? 'YES' : 'NO') === $row['is_nullable'],
        sprintf('Nullability mismatch for %s.%s.', $tableName, $columnName),
    );
}

function doctrineIntegrityAssertNoPendingMigrations(Connection $connection): void
{
    $migrationFiles = glob(dirname(__DIR__, 2).'/migrations/Version*.php') ?: [];
    $availableVersions = array_map(
        static fn (string $path): string => 'DoctrineMigrations\\'.pathinfo($path, PATHINFO_FILENAME),
        $migrationFiles,
    );
    sort($availableVersions);

    $executedVersions = $connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version ASC');
    sort($executedVersions);

    ensureDoctrineIntegrity(count($availableVersions) === count($executedVersions), 'Available migration count must match executed migration count.');
    ensureDoctrineIntegrity($availableVersions === $executedVersions, 'There must be no pending or unexpected migrations.');
}

function doctrineIntegrityExpectForeignKeyViolation(Connection $connection, callable $callback, string $scenario): void
{
    $savepointName = 'doctrine_integrity_fk_check';
    $connection->createSavepoint($savepointName);

    try {
        $callback();
    } catch (ForeignKeyConstraintViolationException) {
        $connection->rollbackSavepoint($savepointName);

        return;
    }

    $connection->releaseSavepoint($savepointName);

    throw new RuntimeException(sprintf('Expected a foreign key violation for %s.', $scenario));
}

function doctrineIntegrityFlushGraph(EntityManagerInterface $entityManager, array $entities): void
{
    foreach ($entities as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();
}

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', true);
$kernel->boot();

$entityManager = $kernel->getContainer()->get('doctrine')->getManager();
ensureDoctrineIntegrity($entityManager instanceof EntityManagerInterface, 'Doctrine did not provide an ORM entity manager.');
$connection = $entityManager->getConnection();

doctrineIntegrityAssertNoPendingMigrations($connection);

doctrineIntegrityAssertAssociation($entityManager, Ticket::class, 'createdBy', User::class, false);
doctrineIntegrityAssertAssociation($entityManager, Ticket::class, 'assignedTo', User::class, true);
doctrineIntegrityAssertAssociation($entityManager, Ticket::class, 'category', Category::class, true);
doctrineIntegrityAssertAssociation($entityManager, Intervention::class, 'ticket', Ticket::class, false);
doctrineIntegrityAssertAssociation($entityManager, Intervention::class, 'technician', User::class, false);
doctrineIntegrityAssertAssociation($entityManager, AIAnalysis::class, 'ticket', Ticket::class, false);
doctrineIntegrityAssertAssociation($entityManager, TicketHistory::class, 'ticket', Ticket::class, false);
doctrineIntegrityAssertAssociation($entityManager, TicketHistory::class, 'changedBy', User::class, true);

foreach ([User::class, Ticket::class, Category::class, Intervention::class, AIAnalysis::class, TicketHistory::class] as $className) {
    doctrineIntegrityAssertNoDangerousOneToMany($entityManager, $className);
}

doctrineIntegrityAssertForeignKey($connection, 'ticket', 'created_by_id', 'user', 'id', false);
doctrineIntegrityAssertForeignKey($connection, 'ticket', 'assigned_to_id', 'user', 'id', true);
doctrineIntegrityAssertForeignKey($connection, 'ticket', 'category_id', 'category', 'id', true);
doctrineIntegrityAssertForeignKey($connection, 'intervention', 'ticket_id', 'ticket', 'id', false);
doctrineIntegrityAssertForeignKey($connection, 'intervention', 'technician_id', 'user', 'id', false);
doctrineIntegrityAssertForeignKey($connection, 'aianalysis', 'ticket_id', 'ticket', 'id', false);
doctrineIntegrityAssertForeignKey($connection, 'ticket_history', 'ticket_id', 'ticket', 'id', false);
doctrineIntegrityAssertForeignKey($connection, 'ticket_history', 'changed_by_id', 'user', 'id', true);

$connection->beginTransaction();
try {
    $client = doctrineIntegrityUser('doctrine-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'Doctrine');
    $technician = doctrineIntegrityUser('doctrine-tech-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Doctrine');
    $category = (new Category())->setName('Doctrine '.bin2hex(random_bytes(4)));
    $ticket = doctrineIntegrityTicket($client, $category, $technician);
    $intervention = doctrineIntegrityIntervention($ticket, $technician);
    $analysis = doctrineIntegrityAnalysis($ticket);
    $history = doctrineIntegrityHistory($ticket, $technician);

    doctrineIntegrityFlushGraph($entityManager, [$client, $technician, $category, $ticket, $intervention, $analysis, $history]);

    ensureDoctrineIntegrity(null !== $ticket->getId(), 'Ticket graph was not persisted.');
    ensureDoctrineIntegrity($client === $ticket->getCreatedBy(), 'Ticket.createdBy relation mismatch.');
    ensureDoctrineIntegrity($technician === $ticket->getAssignedTo(), 'Ticket.assignedTo relation mismatch.');
    ensureDoctrineIntegrity($category === $ticket->getCategory(), 'Ticket.category relation mismatch.');
    ensureDoctrineIntegrity($ticket === $intervention->getTicket(), 'Intervention.ticket relation mismatch.');
    ensureDoctrineIntegrity($technician === $intervention->getTechnician(), 'Intervention.technician relation mismatch.');
    ensureDoctrineIntegrity($ticket === $analysis->getTicket(), 'AIAnalysis.ticket relation mismatch.');
    ensureDoctrineIntegrity($ticket === $history->getTicket(), 'TicketHistory.ticket relation mismatch.');
    ensureDoctrineIntegrity($technician === $history->getChangedBy(), 'TicketHistory.changedBy relation mismatch.');
} finally {
    $entityManager->clear();
    $connection->rollBack();
}

$ticketDeletionScenarios = [
    'ticket referenced by TicketHistory' => static function (User $client, User $technician, Ticket $ticket): array {
        return [doctrineIntegrityHistory($ticket, $technician)];
    },
    'ticket referenced by Intervention' => static function (User $client, User $technician, Ticket $ticket): array {
        return [doctrineIntegrityIntervention($ticket, $technician)];
    },
    'ticket referenced by AIAnalysis' => static function (User $client, User $technician, Ticket $ticket): array {
        return [doctrineIntegrityAnalysis($ticket)];
    },
];

foreach ($ticketDeletionScenarios as $scenario => $relatedFactory) {
    $connection->beginTransaction();

    try {
        $client = doctrineIntegrityUser('ticket-delete-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'Delete');
        $technician = doctrineIntegrityUser('ticket-delete-tech-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_TECHNICIAN'], 'Tech', 'Delete');
        $ticket = doctrineIntegrityTicket($client, null, null);
        $relatedEntities = $relatedFactory($client, $technician, $ticket);
        doctrineIntegrityFlushGraph($entityManager, array_merge([$client, $technician, $ticket], $relatedEntities));

        $ticketId = $ticket->getId();
        ensureDoctrineIntegrity(null !== $ticketId, $scenario.': ticket id is missing.');

        doctrineIntegrityExpectForeignKeyViolation(
            $connection,
            static fn () => $connection->executeStatement('DELETE FROM ticket WHERE id = ?', [$ticketId]),
            $scenario,
        );

        ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket WHERE id = ?', [$ticketId]), $scenario.': ticket must remain.');
        if ('ticket referenced by TicketHistory' === $scenario) {
            ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE ticket_id = ?', [$ticketId]), 'TicketHistory must remain after refused ticket deletion.');
        }
        if ('ticket referenced by Intervention' === $scenario) {
            ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM intervention WHERE ticket_id = ?', [$ticketId]), 'Intervention must remain after refused ticket deletion.');
        }
        if ('ticket referenced by AIAnalysis' === $scenario) {
            ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM aianalysis WHERE ticket_id = ?', [$ticketId]), 'AIAnalysis must remain after refused ticket deletion.');
        }
    } finally {
        $entityManager->clear();
        $connection->rollBack();
    }
}

$userDeletionScenarios = [
    'user used as Ticket.createdBy' => static function (User $user, User $otherUser): array {
        return [doctrineIntegrityTicket($user)];
    },
    'user used as Ticket.assignedTo' => static function (User $user, User $otherUser): array {
        return [doctrineIntegrityTicket($otherUser, null, $user)];
    },
    'user used as Intervention.technician' => static function (User $user, User $otherUser): array {
        $ticket = doctrineIntegrityTicket($otherUser);

        return [$ticket, doctrineIntegrityIntervention($ticket, $user)];
    },
    'user used as TicketHistory.changedBy' => static function (User $user, User $otherUser): array {
        $ticket = doctrineIntegrityTicket($otherUser);

        return [$ticket, doctrineIntegrityHistory($ticket, $user)];
    },
];

foreach ($userDeletionScenarios as $scenario => $relatedFactory) {
    $connection->beginTransaction();

    try {
        $user = doctrineIntegrityUser('user-delete-target-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_TECHNICIAN'], 'Target', 'Delete');
        $otherUser = doctrineIntegrityUser('user-delete-other-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Other', 'Delete');
        $relatedEntities = $relatedFactory($user, $otherUser);
        doctrineIntegrityFlushGraph($entityManager, array_merge([$user, $otherUser], $relatedEntities));

        $userId = $user->getId();
        ensureDoctrineIntegrity(null !== $userId, $scenario.': user id is missing.');

        doctrineIntegrityExpectForeignKeyViolation(
            $connection,
            static fn () => $connection->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]),
            $scenario,
        );

        ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$userId]), $scenario.': user must remain.');
    } finally {
        $entityManager->clear();
        $connection->rollBack();
    }
}

$connection->beginTransaction();
try {
    $client = doctrineIntegrityUser('nullable-history-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'History');
    $ticket = doctrineIntegrityTicket($client);
    $systemHistory = doctrineIntegrityHistory($ticket, null);
    doctrineIntegrityFlushGraph($entityManager, [$client, $ticket, $systemHistory]);

    ensureDoctrineIntegrity(null !== $systemHistory->getId(), 'TicketHistory with changedBy null must be persisted.');
    ensureDoctrineIntegrity(null === $systemHistory->getChangedBy(), 'TicketHistory.changedBy must remain null for system events.');
    ensureDoctrineIntegrity(
        1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM ticket_history WHERE id = ? AND changed_by_id IS NULL', [$systemHistory->getId()]),
        'Database must accept TicketHistory.changedBy null.',
    );
} finally {
    $entityManager->clear();
    $connection->rollBack();
}

$connection->beginTransaction();
try {
    $client = doctrineIntegrityUser('category-delete-client-'.bin2hex(random_bytes(6)).'@example.test', ['ROLE_CLIENT'], 'Client', 'Category');
    $category = (new Category())->setName('Category delete '.bin2hex(random_bytes(4)));
    $ticket = doctrineIntegrityTicket($client, $category);
    doctrineIntegrityFlushGraph($entityManager, [$client, $category, $ticket]);

    $categoryId = $category->getId();
    $ticketId = $ticket->getId();
    ensureDoctrineIntegrity(null !== $categoryId && null !== $ticketId, 'Category deletion scenario ids are missing.');

    doctrineIntegrityExpectForeignKeyViolation(
        $connection,
        static fn () => $connection->executeStatement('DELETE FROM category WHERE id = ?', [$categoryId]),
        'category referenced by ticket',
    );

    ensureDoctrineIntegrity(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM category WHERE id = ?', [$categoryId]), 'Referenced category must remain.');
    ensureDoctrineIntegrity(
        $categoryId === (int) $connection->fetchOne('SELECT category_id FROM ticket WHERE id = ?', [$ticketId]),
        'Ticket category must remain associated after refused category deletion.',
    );
} finally {
    $entityManager->clear();
    $connection->rollBack();
}

echo "Doctrine integrity tests: PASS\n";
