<?php

namespace App\Repository;

use App\Entity\Intervention;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Intervention>
 */
class InterventionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Intervention::class);
    }

    /**
     * @return list<Intervention>
     */
    public function findClientVisibleForTicket(Ticket $ticket): array
    {
        return $this->createQueryBuilder('intervention')
            ->andWhere('intervention.ticket = :ticket')
            ->andWhere('intervention.isClientVisible = :isClientVisible')
            ->setParameter('ticket', $ticket)
            ->setParameter('isClientVisible', true)
            ->orderBy('intervention.createdAt', 'ASC')
            ->addOrderBy('intervention.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Intervention[] Returns an array of Intervention objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('i.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Intervention
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
