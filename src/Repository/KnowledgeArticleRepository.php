<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnowledgeArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KnowledgeArticle>
 */
class KnowledgeArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KnowledgeArticle::class);
    }

    /**
     * @return list<KnowledgeArticle>
     */
    public function findClientSafeActive(): array
    {
        return $this->createQueryBuilder('article')
            ->andWhere('article.isActive = :active')
            ->andWhere('article.isClientSafe = :clientSafe')
            ->setParameter('active', true)
            ->setParameter('clientSafe', true)
            ->orderBy('article.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
