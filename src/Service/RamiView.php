<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RamiGame;
use App\Entity\RamiRound;
use App\Enum\RoomStatus;
use App\Game\Rami\RamiEngine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un joueur voit d'une partie de rami : sa main, et des autres seulement leur nombre de cartes ;
 * la pioche (son nombre de cartes), la carte du dessus de la défausse, les combinaisons posées, les pénalités
 * et la dernière manche terminée.
 */
final readonly class RamiView
{
    public function __construct(
        private BeloteView $belote,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(RamiGame $game, int $me): array
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
            'opened' => $state->opened[$player['position']] ?? false,
            'score' => $scores[$player['position']] ?? 0,
        ], $this->belote->players($room->getSeats()));

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $finished ? 'terminee' : 'jeu',
            'round' => $game->getRound(),
            'target' => $room->getTargetPoints(),
            'firstMeld' => RamiEngine::FIRST_MELD,
            'turn' => $state->turn,
            'step' => $state->phase,
            'seconds' => $room->getTurnSeconds(),
            'secondsLeft' => $finished ? 0 : round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'hand' => $state->hands[$me],
            'taken' => $state->turn === $me ? $state->taken : null,
            'stock' => \count($state->stock),
            'discard' => [] === $state->discard ? null : $state->discard[\count($state->discard) - 1],
            'melds' => array_map(static fn (array $entry, int $index): array => [
                'index' => $index,
                'owner' => $entry['owner'],
                ...$entry['meld']->toArray(),
            ], $state->melds, array_keys($state->melds)),
            'players' => $players,
            'lastRound' => $this->lastRound($game),
            'winner' => null === $winner ? null : $winner - 1,
            'draw' => $finished && null === $winner,
        ];
    }

    /**
     * La dernière manche terminée (pour l'annoncer), ou null.
     *
     * @return array{number: int, winner: ?int, ramiSec: bool, penalties: list<int>}|null
     */
    private function lastRound(RamiGame $game): ?array
    {
        $round = $this->entityManager->getRepository(RamiRound::class)->findOneBy(['game' => $game], ['number' => 'DESC']);

        return null === $round ? null : [
            'number' => $round->getNumber(),
            'winner' => $round->getWinner(),
            'ramiSec' => $round->isRamiSec(),
            'penalties' => $round->getPenalties(),
        ];
    }
}
