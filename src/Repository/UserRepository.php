<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findEmailIngestionUser(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function countByPersistedRole(string $role): int
    {
        return (int) $this->getEntityManager()
            ->getConnection()
            ->fetchOne(
                'SELECT COUNT(id) FROM "user" WHERE jsonb_exists(roles::jsonb, :role)',
                ['role' => $role],
            );
    }

    /**
     * @return list<array{
     *     id: int,
     *     email: string,
     *     roles: list<string>,
     *     firstname: string,
     *     lastname: string,
     *     isActive: bool,
     *     createdAt: \DateTimeImmutable
     * }>
     */
    public function findAllForAdminList(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(
                <<<'SQL'
                    SELECT id, email, roles, firstname, lastname, is_active, created_at
                    FROM "user"
                    ORDER BY created_at DESC, id DESC
                    SQL,
            );

        return array_map(
            static function (array $row): array {
                $roles = json_decode((string) $row['roles'], true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'roles' => array_values(array_filter($roles, 'is_string')),
                    'firstname' => (string) $row['firstname'],
                    'lastname' => (string) $row['lastname'],
                    'isActive' => filter_var($row['is_active'], FILTER_VALIDATE_BOOL),
                    'createdAt' => new \DateTimeImmutable((string) $row['created_at']),
                ];
            },
            $rows,
        );
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
