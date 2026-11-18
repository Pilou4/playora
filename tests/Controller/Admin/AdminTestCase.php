<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\BeloteGame;
use App\Entity\Profile;
use App\Entity\PatienceGame;
use App\Entity\GolfGame;
use App\Entity\HuitGame;
use App\Entity\RamiGame;
use App\Entity\TarotGame;
use App\Entity\User;
use App\Enum\Game;
use App\Service\GameRoomManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base des tests de l'administration : client, comptes (joueur ou admin), parties contre des robots, nettoyage.
 */
abstract class AdminTestCase extends WebTestCase
{
    protected const string AVATAR = 'portrait.0.0.1.0.2.2.0.0.0.0.0.0.0.1.0.0.0.0.1';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    protected function gameAgainstBots(User $user): BeloteGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Belote, 501, false);

        foreach ([2, 3, 4] as $position) {
            $manager->addBot($user, $room, $position);
        }

        return $this->entityManager->getRepository(BeloteGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    protected function tarotAgainstBots(User $user, int $players = 4): TarotGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Tarot, Game::Tarot->defaultTargetPoints(), false, $players);

        foreach (range(2, $players) as $position) {
            $manager->addBot($user, $room, $position);
        }

        return $this->entityManager->getRepository(TarotGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    protected function ramiAgainstBots(User $user): RamiGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Rami, 500, false, 2);
        $manager->addBot($user, $room, 2);

        return $this->entityManager->getRepository(RamiGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    protected function huitAgainstBots(User $user): HuitGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::HuitAmericain, 300, false, 2);
        $manager->addBot($user, $room, 2);

        return $this->entityManager->getRepository(HuitGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    protected function golfAgainstBots(User $user): GolfGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Golf, 9, false, 2);
        $manager->addBot($user, $room, 2);

        return $this->entityManager->getRepository(GolfGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    /**
     * Duel solo de solitaire (sans robots) : le créateur joue aussitôt, l'adversaire le rejoint.
     */
    protected function soloDuel(User $user, User $opponent): PatienceGame
    {
        $manager = static::getContainer()->get(GameRoomManager::class);
        $room = $manager->create($user, Game::Solitaire, 1, false);
        $manager->join($opponent, $room);

        return $this->entityManager->getRepository(PatienceGame::class)->findOneBy(['room' => $room]) ?? self::fail('Partie non créée.');
    }

    protected function createUser(string $pseudo, bool $admin = false): User
    {
        $user = new User(bin2hex(random_bytes(6)).'@exemple.fr', $pseudo);
        $user->setPassword('pas-utilise-dans-ces-tests');
        $user->markEmailAsVerified();

        if ($admin) {
            $user->grantRole('ROLE_ADMIN');
        }

        $profile = new Profile($user);
        $profile->setAvatar(self::AVATAR);
        $this->entityManager->persist($user);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }

    protected function purge(): void
    {
        $connection = $this->entityManager->getConnection();

        foreach (['game_setting', 'golf_hole', 'golf_game', 'huit_round', 'huit_game', 'rami_round', 'rami_game', 'solitaire_board', 'solitaire_game', 'tarot_deal', 'tarot_game', 'belote_deal', 'belote_game', 'game_player', 'game_room_seat', 'game_room', 'reset_password_request', 'profile', '`user`'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
