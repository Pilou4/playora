<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GolfGame;
use App\Entity\GolfHole;
use App\Enum\RoomStatus;
use App\Game\Golf\GolfState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qu'un joueur voit d'une partie de Golf : les 6 cartes de chacun, seulement celles qui sont retournées
 * (les cartes cachées restent secrètes, même les siennes) ; la pioche (son nombre de cartes), la carte du dessus
 * de la défausse, la carte piochée par le joueur dont c'est le tour, les points et le dernier trou terminé.
 */
final readonly class GolfView
{
    public function __construct(
        private BeloteView $belote,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(GolfGame $game, int $me): array
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
            'grid' => self::visible($state, $player['position']),
            'score' => $scores[$player['position']] ?? 0,
        ], $this->belote->players($room->getSeats()));

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $finished ? 'terminee' : $state->phase,
            'hole' => $game->getHole(),
            'target' => $room->getTargetPoints(),
            'turn' => $state->turn,
            'closer' => $state->closer,
            'seconds' => $room->getTurnSeconds(),
            'secondsLeft' => $finished ? 0 : round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'drawn' => $state->drawn,
            'stock' => \count($state->stock),
            'discard' => $state->top(),
            'players' => $players,
            'lastHole' => $this->lastHole($game),
            'winner' => null === $winner ? null : $winner - 1,
            'draw' => $finished && null === $winner,
        ];
    }

    /**
     * Les 6 cartes d'une place : le code des cartes retournées, null pour les cartes cachées.
     *
     * @return list<?string>
     */
    private static function visible(GolfState $state, int $position): array
    {
        $grid = $state->grids[$position] ?? [];

        return array_map(static fn (string $code, int $index): ?string => ($state->up[$position][$index] ?? false) ? $code : null, $grid, array_keys($grid));
    }

    /**
     * @return array{number: int, closer: ?int, points: list<int>}|null
     */
    private function lastHole(GolfGame $game): ?array
    {
        $hole = $this->entityManager->getRepository(GolfHole::class)->findOneBy(['game' => $game], ['number' => 'DESC']);

        return null === $hole ? null : [
            'number' => $hole->getNumber(),
            'closer' => $hole->getCloser(),
            'points' => $hole->getPoints(),
        ];
    }
}
