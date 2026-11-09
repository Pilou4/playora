<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Profile>
 */
final class ProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    public function findOneByUser(User $user): ?Profile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Avatars de plusieurs joueurs en une seule requête (identifiant du compte => code).
     *
     * @param list<User> $users
     *
     * @return array<int, string>
     */
    public function findAvatarCodes(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.user) AS user', 'p.avatar')
            ->where('p.user IN (:users)')
            ->andWhere('p.avatar IS NOT NULL')
            ->setParameter('users', $users)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'avatar', 'user');
    }
}
