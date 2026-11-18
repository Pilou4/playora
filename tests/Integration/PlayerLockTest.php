<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Service\PlayerLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Une action à la fois par joueur pour s'asseoir à une table : la seconde attend, puis abandonne.
 */
final class PlayerLockTest extends KernelTestCase
{
    public function testASecondActionOfTheSamePlayerWaitsForTheFirst(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $lock = self::getContainer()->get(PlayerLock::class);
        $fred = $this->user(41);
        $bob = $this->user(42);

        // Une autre requête (une autre connexion à la base) est en train d'installer Fred
        $other = DriverManager::getConnection($connection->getParams());
        self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', ['playora_joueur_41']));

        try {
            $lock->run($fred, static fn (): string => 'jamais', 0);
            self::fail('La seconde action de Fred aurait dû être refusée.');
        } catch (\DomainException $exception) {
            self::assertSame(PlayerLock::BUSY, $exception->getMessage());
        }

        // Bob, lui, n'attend pas
        self::assertSame('ok', $lock->run($bob, static fn (): string => 'ok', 0));

        // L'autre action finie, Fred passe ; le verrou est rendu même si l'action échoue
        $other->executeStatement('DO RELEASE_LOCK(?)', ['playora_joueur_41']);
        self::assertSame('ok', $lock->run($fred, static fn (): string => 'ok', 0));

        try {
            $lock->run($fred, static fn () => throw new \DomainException('Place prise.'), 0);
        } catch (\DomainException) {
        }

        self::assertSame(1, (int) $other->fetchOne('SELECT GET_LOCK(?, 0)', ['playora_joueur_41']));
        $other->executeStatement('DO RELEASE_LOCK(?)', ['playora_joueur_41']);
        $other->close();
    }

    private function user(int $id): User
    {
        $user = new User('joueur'.$id.'@exemple.fr', 'Joueur'.$id);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
