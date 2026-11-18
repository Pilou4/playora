<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HuitGame;
use App\Entity\HuitRound;
use App\Enum\RoomStatus;
use App\Game\Huit\HuitEngine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un joueur voit d'une partie de 8 américain : sa main et les cartes qu'il peut poser, et des autres
 * seulement leur nombre de cartes ; la pioche (son nombre de cartes), la pile, la couleur demandée, le sens du jeu,
 * les points et la dernière manche terminée.
 */
final readonly class HuitView
{
    public function __construct(
        private BeloteView $belote,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(HuitGame $game, int $me): array
    {
        $state = $game->getState();
        $room = $game->getRoom();
        $scores = $game->getScores();
        $finished = RoomStatus::Closed === $room->getStatus();
        $winner = $game->getWinnerPosition();

        $players = array_map(static fn (array $player): array => [
            'position' => $player['position'],
            'pseudo' => $player['pseudo'],
            'bot' => $player['bot'],
            'avatar' => $player['avatar'],
            'cards' => \count($state->hands[$player['position']] ?? []),
            'score' => $scores[$player['position']] ?? 0,
        ], $this->belote->players($room->getSeats()));

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $finished ? 'terminee' : 'jeu',
            'round' => $game->getRound(),
            'target' => $room->getTargetPoints(),
            'turn' => $state->turn,
            'direction' => $state->direction,
            'suit' => $state->suit,
            'penalty' => $state->penalty,
            'seconds' => $room->getTurnSeconds(),
            'secondsLeft' => $finished ? 0 : round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'hand' => $state->hands[$me],
            'playable' => (new HuitEngine())->playable($state, $me),
            'drawn' => $state->turn === $me ? $state->drawn : null,
            'stock' => \count($state->stock),
            // Les 3 dernières cartes de la pile (la dernière est celle du dessus)
            'pile' => \array_slice($state->pile, -3),
            'players' => $players,
            'lastRound' => $this->lastRound($game),
            'winner' => null === $winner ? null : $winner - 1,
            'draw' => $finished && null === $winner,
        ];
    }

    /**
     * @return array{number: int, winner: ?int, penalties: list<int>}|null
     */
    private function lastRound(HuitGame $game): ?array
    {
        $round = $this->entityManager->getRepository(HuitRound::class)->findOneBy(['game' => $game], ['number' => 'DESC']);

        return null === $round ? null : [
            'number' => $round->getNumber(),
            'winner' => $round->getWinner(),
            'penalties' => $round->getPenalties(),
        ];
    }
}
