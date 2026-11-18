<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PresidentGame;
use App\Entity\PresidentRound;
use App\Enum\RoomStatus;
use App\Game\President\PresidentEngine;
use App\Game\President\PresidentState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un joueur voit d'une partie de Président : sa main et ce qu'il peut poser, le pli en cours, et des autres
 * seulement leur nombre de cartes, leur titre, leur place s'ils ont fini et s'ils ont passé ; l'échange de cartes
 * (ce qu'il a reçu et donné), les points et la dernière manche terminée.
 */
final readonly class PresidentView
{
    public function __construct(
        private BeloteView $belote,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(PresidentGame $game, int $me): array
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
            'role' => $state->roles[$player['position']] ?? null,
            // Place dans la manche en cours, s'il a déjà fini (1 : Président)
            'place' => false === ($rank = array_search($player['position'], $state->order, true)) ? null : $rank + 1,
            'passed' => \in_array($player['position'], $state->passed, true),
            // Échange : cartes qu'il doit encore rendre
            'giving' => PresidentState::EXCHANGE === $state->phase ? ($state->toGive[$player['position']] ?? 0) : 0,
        ], $this->belote->players($room->getSeats()));

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $finished ? 'terminee' : $state->phase,
            'round' => $game->getRound(),
            'target' => $room->getTargetPoints(),
            'goal' => $game->goal(),
            'turn' => $state->turn,
            'seconds' => $room->getTurnSeconds(),
            'secondsLeft' => $finished ? 0 : round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'hand' => $state->hands[$me],
            'options' => (new PresidentEngine())->options($state, $me),
            'trick' => $state->trick,
            'cleared' => $state->cleared,
            'exchange' => [
                'toGive' => PresidentState::EXCHANGE === $state->phase ? ($state->toGive[$me] ?? 0) : 0,
                'giveTo' => $state->giveTo[$me] ?? null,
                'received' => $state->received[$me] ?? [],
                'given' => $state->given[$me] ?? [],
            ],
            'players' => $players,
            'lastRound' => $this->lastRound($game),
            'winner' => null === $winner ? null : $winner - 1,
        ];
    }

    /**
     * @return array{number: int, order: list<int>, points: list<int>}|null
     */
    private function lastRound(PresidentGame $game): ?array
    {
        $round = $this->entityManager->getRepository(PresidentRound::class)->findOneBy(['game' => $game], ['number' => 'DESC']);

        return null === $round ? null : [
            'number' => $round->getNumber(),
            'order' => $round->getOrder(),
            'points' => $round->getPoints(),
        ];
    }
}
