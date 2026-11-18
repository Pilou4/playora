<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

/**
 * Robot de solitaire (mode test) : il joue sa donne d'un trait, avec des priorités simples
 * (ranger sur les familles, découvrir une carte cachée, poser la carte du talon, retourner le talon),
 * puis s'arrête quand plus rien n'avance. Ses coups passent par le moteur, comme ceux d'un joueur.
 */
final readonly class SolitaireBot
{
    /** Garde-fou : nombre maximal de coups. */
    private const int MAX_ACTIONS = 1500;
    /** Temps « mis » par le robot : quelques secondes de réflexion, plus un peu par coup. */
    private const int THINK_SECONDS = 20;
    private const int SECONDS_PER_MOVE = 2;

    public function __construct(
        private SolitaireEngine $engine,
    ) {
    }

    /**
     * Joue la donne jusqu'au bout et la termine (bonus de temps compris). Renvoie le temps qu'il a mis, en secondes.
     */
    public function play(SolitaireState $state): int
    {
        $idleDraws = 0;

        for ($i = 0; $i < self::MAX_ACTIONS && !$state->finished; ++$i) {
            // Tout est visible et la fin se joue toute seule
            if ([] !== $this->engine->autoComplete($state)) {
                break;
            }

            if ($this->moveOnce($state)) {
                $idleDraws = 0;
                continue;
            }

            // Rien ne bouge depuis un tour complet du talon, ou le prochain retournement serait payant : il s'arrête
            $cycle = \count($state->stock) + \count($state->waste);
            $paidRecycle = [] === $state->stock && $state->passes + 1 >= SolitaireScore::FREE_PASSES;

            if (0 === $cycle || $idleDraws > $cycle || $paidRecycle || $state->lastCards()) {
                break;
            }

            $this->engine->draw($state);
            ++$idleDraws;
        }

        $seconds = min(SolitaireScore::SECONDS, self::THINK_SECONDS + $state->moves * self::SECONDS_PER_MOVE);
        $this->engine->finish($state, SolitaireScore::SECONDS - $seconds, 52 === $state->sorted());

        return $seconds;
    }

    /**
     * Le meilleur coup du moment, s'il y en a un.
     */
    private function moveOnce(SolitaireState $state): bool
    {
        $targets = $this->engine->targets($state);
        $waste = [] === $state->waste ? null : $state->waste[\count($state->waste) - 1];

        // 1. Ranger sur une famille (talon ou pile de tri)
        foreach ($targets as $code => $places) {
            if (\in_array('famille', $places, true) && $this->isOnBoard($state, $code)) {
                $this->engine->move($state, $code, 'famille');

                return true;
            }
        }

        // 2. Déplacer un bloc qui découvre une carte cachée
        foreach ($state->tableau as $cards) {
            $first = $this->firstVisible($cards);

            if (null !== $first && $first > 0 && isset($targets[$cards[$first][0]])) {
                $column = $this->bestColumn($targets[$cards[$first][0]], $state);

                if (null !== $column) {
                    $this->engine->move($state, $cards[$first][0], $column);

                    return true;
                }
            }
        }

        // 3. Poser la carte du talon sur une pile
        if (null !== $waste && isset($targets[$waste])) {
            $column = $this->bestColumn($targets[$waste], $state);

            if (null !== $column) {
                $this->engine->move($state, $waste, $column);

                return true;
            }
        }

        return false;
    }

    /**
     * Une pile de tri qui prend la carte, en préférant une pile non vide (on garde les places vides pour les Rois utiles).
     *
     * @param list<string> $places
     */
    private function bestColumn(array $places, SolitaireState $state): ?string
    {
        $empty = null;

        foreach ($places as $place) {
            if ('famille' === $place) {
                continue;
            }

            if ([] !== $state->tableau[(int) substr($place, 8)]) {
                return $place;
            }

            $empty ??= $place;
        }

        return $empty;
    }

    /**
     * @param list<array{string, bool}> $cards
     */
    private function firstVisible(array $cards): ?int
    {
        foreach ($cards as $row => [, $up]) {
            if ($up) {
                return $row;
            }
        }

        return null;
    }

    /** Une carte du talon ou d'une pile (pas le dessus d'une famille, qui y est déjà). */
    private function isOnBoard(SolitaireState $state, string $code): bool
    {
        $card = SolitaireCard::fromCode($code);

        return $state->foundations[$card->suit] !== $card->rank;
    }
}
