<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * @return list<Ticket>
     */
    public function findRecentForClient(User $client, int $limit = 2): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.createdBy = :client')
            ->setParameter('client', $client)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->setMaxResults(max(1, min(2, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findAllForClient(User $client): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.createdBy = :client')
            ->setParameter('client', $client)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenTickets(): int
    {
        return $this->countByStatus(Ticket::STATUS_OPEN);
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('ticket')
            ->select('COUNT(ticket.id)')
            ->andWhere('ticket.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findOpenTickets(): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.status = :status')
            ->setParameter('status', Ticket::STATUS_OPEN)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAssignedToTechnicianExcludingClosed(User $technician): int
    {
        return (int) $this->createQueryBuilder('ticket')
            ->select('COUNT(ticket.id)')
            ->andWhere('ticket.assignedTo = :technician')
            ->andWhere('ticket.status != :closedStatus')
            ->setParameter('technician', $technician)
            ->setParameter('closedStatus', Ticket::STATUS_CLOSED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findAllAssignedToTechnicianExcludingClosed(User $technician): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.assignedTo = :technician')
            ->andWhere('ticket.status != :closedStatus')
            ->setParameter('technician', $technician)
            ->setParameter('closedStatus', Ticket::STATUS_CLOSED)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenUnassignedTickets(): int
    {
        return (int) $this->createQueryBuilder('ticket')
            ->select('COUNT(ticket.id)')
            ->andWhere('ticket.assignedTo IS NULL')
            ->andWhere('ticket.status = :status')
            ->setParameter('status', Ticket::STATUS_OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findOpenUnassignedTickets(int $limit = 5): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.assignedTo IS NULL')
            ->andWhere('ticket.status = :status')
            ->setParameter('status', Ticket::STATUS_OPEN)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->setMaxResults(max(1, min(5, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findAssignedToTechnicianExcludingClosed(User $technician, int $limit = 5): array
    {
        return $this->createQueryBuilder('ticket')
            ->andWhere('ticket.assignedTo = :technician')
            ->andWhere('ticket.status != :closedStatus')
            ->setParameter('technician', $technician)
            ->setParameter('closedStatus', Ticket::STATUS_CLOSED)
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->setMaxResults(max(1, min(5, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Ticket>
     */
    public function findRecentForAdmin(int $limit = 5): array
    {
        return $this->createQueryBuilder('ticket')
            ->orderBy('ticket.createdAt', 'DESC')
            ->addOrderBy('ticket.id', 'DESC')
            ->setMaxResults(max(1, min(5, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByPriorityForAdminStats(): array
    {
        $rows = $this->createQueryBuilder('ticket')
            ->select('ticket.priority AS priority')
            ->addSelect('COUNT(ticket.id) AS ticketCount')
            ->groupBy('ticket.priority')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['priority']] = (int) $row['ticketCount'];
        }

        return $counts;
    }

    /**
     * @return list<array{label: string, ticketCount: int, categoryId: ?int}>
     */
    public function countByCategoryForAdminStats(): array
    {
        $rows = $this->createQueryBuilder('ticket')
            ->select('category.id AS categoryId')
            ->addSelect('COALESCE(category.name, :uncategorizedLabel) AS label')
            ->addSelect('COUNT(ticket.id) AS ticketCount')
            ->leftJoin('ticket.category', 'category')
            ->setParameter('uncategorizedLabel', 'Non catégorisé')
            ->groupBy('category.id')
            ->addGroupBy('category.name')
            ->orderBy('ticketCount', 'DESC')
            ->addOrderBy('label', 'ASC')
            ->addOrderBy('category.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'label' => (string) $row['label'],
                'ticketCount' => (int) $row['ticketCount'],
                'categoryId' => null === $row['categoryId'] ? null : (int) $row['categoryId'],
            ],
            $rows,
        );
    }

    /**
     * @return array<string, int>
     */
    public function countCreatedByDayForAdminStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(
                <<<'SQL'
                    SELECT DATE(created_at) AS creation_day, COUNT(id) AS ticket_count
                    FROM ticket
                    WHERE created_at >= :startDate
                      AND created_at < :endDate
                    GROUP BY creation_day
                    ORDER BY creation_day ASC
                    SQL,
                [
                    'startDate' => $start->format('Y-m-d H:i:s'),
                    'endDate' => $end->format('Y-m-d H:i:s'),
                ],
            );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['creation_day']] = (int) $row['ticket_count'];
        }

        return $counts;
    }

    /**
     * @param list<string> $terms
     *
     * @return list<Ticket>
     */
    public function findSimilarityCandidates(Ticket $ticket, array $terms, string $normalizedTitle, int $limit = 50): array
    {
        $ticketId = $ticket->getId();

        if (null === $ticketId) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('candidate')
            ->andWhere('candidate.id != :ticketId')
            ->setParameter('ticketId', $ticketId)
            ->orderBy('candidate.createdAt', 'DESC')
            ->addOrderBy('candidate.id', 'DESC')
            ->setMaxResults(max(1, min(50, $limit)));

        $matches = $queryBuilder->expr()->orX();
        $categoryId = $ticket->getCategory()?->getId();

        if (null !== $categoryId) {
            $matches->add('IDENTITY(candidate.category) = :categoryId');
            $queryBuilder->setParameter('categoryId', $categoryId);
        }

        if ('' !== $normalizedTitle) {
            $matches->add('TRIM(LOWER(candidate.title)) = :normalizedTitle');
            $queryBuilder->setParameter('normalizedTitle', $normalizedTitle);
        }

        foreach (array_slice($terms, 0, 20) as $index => $term) {
            $parameter = 'term_'.$index;
            $matches->add(sprintf('LOWER(candidate.title) LIKE :%s', $parameter));
            $matches->add(sprintf('LOWER(candidate.description) LIKE :%s', $parameter));
            $queryBuilder->setParameter($parameter, '%'.$term.'%');
        }

        if (0 === $matches->count()) {
            return [];
        }

        return $queryBuilder
            ->andWhere($matches)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Ticket[] Returns an array of Ticket objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Ticket
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
