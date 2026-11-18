<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameSetting;
use App\Enum\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSetting>
 */
final class GameSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSetting::class);
    }

    /**
     * Les réglages du jeu ; s'ils n'ont jamais été enregistrés, ceux par défaut (pas encore en base).
     */
    public function forGame(Game $game): GameSetting
    {
        return $this->findOneBy(['game' => $game]) ?? new GameSetting($game);
    }

    /**
     * Les jeux mis inactifs par l'admin (un jeu sans réglages en base est actif).
     *
     * @return list<Game>
     */
    public function inactiveGames(): array
    {
        $rows = $this->createQueryBuilder('setting')
            ->select('setting.game')
            ->where('setting.active = false')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): Game => $row['game'] instanceof Game ? $row['game'] : Game::from($row['game']), $rows));
    }
}
