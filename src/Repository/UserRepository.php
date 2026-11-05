<?php

declare(strict_types=1);

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
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function emailExists(string $email): bool
    {
        return $this->exists('email', User::normalizeEmail($email));
    }

    /**
     * Sans tenir compte des majuscules ni des accents (collation de la colonne).
     */
    public function pseudoExists(string $pseudo): bool
    {
        return $this->exists('pseudo', trim($pseudo));
    }

    /**
     * Symfony rehache automatiquement le mot de passe à la connexion
     * quand l'algorithme recommandé change.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Les instances de "%s" ne sont pas prises en charge.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    private function exists(string $field, string $value): bool
    {
        return (bool) $this->createQueryBuilder('u')
            ->select('1')
            ->where(\sprintf('u.%s = :value', $field))
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
