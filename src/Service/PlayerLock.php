<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Une action à la fois par joueur pour s'asseoir à une table (créer un salon, rejoindre une place) : deux onglets,
 * deux appareils ou deux clics simultanés ne peuvent pas l'installer à deux tables, ni deux fois à la même.
 * Verrou nommé de la base (GET_LOCK) : il ne dépend d'aucune transaction et se libère tout seul si la connexion tombe.
 */
final readonly class PlayerLock
{
    public const string BUSY = 'Une autre action est déjà en cours sur votre compte : réessayez dans un instant.';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     *
     * @throws \DomainException si une autre action du joueur dure plus de $wait secondes
     */
    public function run(User $user, \Closure $action, int $wait = 5): mixed
    {
        $name = 'playora_joueur_'.$user->getId();

        if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $wait])) {
            throw new \DomainException(self::BUSY);
        }

        try {
            return $action();
        } finally {
            $this->connection->executeStatement('DO RELEASE_LOCK(?)', [$name]);
        }
    }
}
