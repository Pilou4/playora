<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PatienceBoard;
use App\Entity\PatienceGame;
use App\Game\Patience\PatienceState;
use App\Repository\PatienceBoardRepository;

/**
 * Ce qu'un joueur voit d'un duel solo (solitaire, Spider), en dehors de sa grille : phase, temps, son score, les autres
 * places et, une fois sa propre partie finie, le résultat. Les scores des autres joueurs restent cachés tant que sa
 * partie n'est pas terminée ; ensuite, il voit ceux qui ont fini (les autres « en cours », les places libres).
 * La grille elle-même est décrite par la vue du jeu (SolitaireView, SpiderView).
 */
final readonly class PatienceView
{
    /** Phases vues par la page. */
    public const string PLAYING = 'jeu';
    public const string WAITING = 'attente';
    public const string FINISHED = 'terminee';

    public function __construct(
        private PatienceBoardRepository $boards,
        private PatienceTable $table,
        private BeloteView $belote,
    ) {
    }

    /**
     * @param \Closure(PatienceState, PatienceBoard): array<string, mixed> $board décrit la grille du joueur
     *
     * @return array<string, mixed>
     */
    public function forPlayer(PatienceGame $game, int $me, \Closure $board): array
    {
        $now = new \DateTimeImmutable();
        $room = $game->getRoom();
        $boards = $this->boards->forRound($game, $game->getRound());
        $mine = $boards[$me] ?? throw new \LogicException('Grille introuvable.');
        $done = $mine->isFinished();
        $free = [];

        foreach ($room->getSeats() as $seat) {
            $free[$seat->getPosition() - 1] = $seat->isFree();
        }

        $players = array_map(static function (array $player) use ($boards, $free, $done): array {
            $other = $boards[$player['position']] ?? null;
            $isFree = $free[$player['position']] ?? false;

            return [
                'position' => $player['position'],
                'pseudo' => $isFree ? 'Place libre' : $player['pseudo'],
                'avatar' => $isFree ? null : $player['avatar'],
                'free' => $isFree,
                'done' => $other?->isFinished() ?? false,
                // Le score d'un autre joueur : seulement quand les deux parties sont finies
                'score' => $done && null !== $other && $other->isFinished() ? $other->getScore() : null,
            ];
        }, $this->belote->players($room->getSeats()));

        $winner = $game->getWinnerPosition();
        $state = $this->table->rules($game)->restore($mine->getStateData());

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $this->phase($game, $mine),
            'seconds' => PatienceBoard::SECONDS,
            'secondsLeft' => $done ? 0 : round($mine->secondsLeft($now), 1),
            'board' => [
                ...$board($state, $mine),
                'score' => $state->score(),
                'complete' => $mine->isComplete(),
                'finished' => $done,
            ],
            'players' => $players,
            'winner' => null === $winner ? null : $winner - 1,
            'draw' => $game->isFinished() && null === $winner,
        ];
    }

    private function phase(PatienceGame $game, PatienceBoard $board): string
    {
        return match (true) {
            $game->isFinished() => self::FINISHED,
            $board->isFinished() => self::WAITING,
            default => self::PLAYING,
        };
    }
}
