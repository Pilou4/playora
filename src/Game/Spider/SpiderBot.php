<?php

declare(strict_types=1);

namespace App\Game\Spider;

/**
 * Robot de Spider (mode test) : il joue sa donne d'un trait avec des priorités simples
 * (découvrir une carte cachée, prolonger une suite de même couleur, distribuer quand rien ne bouge),
 * puis s'arrête. Ses coups passent par le moteur, comme ceux d'un joueur ; il n'utilise jamais le Joker.
 */
final readonly class SpiderBot
{
    /** Garde-fou : nombre maximal de coups. */
    private const int MAX_ACTIONS = 2000;
    /** Temps « mis » par le robot : quelques secondes de réflexion, plus un peu par coup. */
    private const int THINK_SECONDS = 20;
    private const int SECONDS_PER_MOVE = 2;

    public function __construct(
        private SpiderEngine $engine,
    ) {
    }

    /**
     * Joue la donne jusqu'au bout et la termine (bonus compris si tout est rangé). Renvoie le temps qu'il a mis, en secondes.
     */
    public function play(SpiderState $state): int
    {
        $seen = [];

        for ($i = 0; $i < self::MAX_ACTIONS && !$state->finished; ++$i) {
            // Une position déjà vue : on tourne en rond, on passe à la suite (distribuer ou s'arrêter)
            $key = md5(serialize($state->columns));
            $repeated = isset($seen[$key]);
            $seen[$key] = true;

            if (!$repeated && $this->moveOnce($state)) {
                continue;
            }

            if (!$this->dealOrFill($state)) {
                break;
            }
        }

        $seconds = min(300, self::THINK_SECONDS + $state->moves * self::SECONDS_PER_MOVE);
        $this->engine->finish($state, 300 - $seconds, $state->isComplete());

        return $seconds;
    }

    /**
     * Le meilleur coup du moment : d'abord ce qui découvre une carte cachée, puis ce qui prolonge une suite de même couleur.
     */
    private function moveOnce(SpiderState $state): bool
    {
        $best = null;
        $bestScore = 0;

        foreach ($this->engine->targets($state) as $code => $places) {
            [$column, $row] = $this->where($state, $code);

            if (null === $column) {
                continue;
            }

            $card = SpiderCard::fromCode($code);
            $below = $row > 0 ? $state->columns[$column][$row - 1] : null;

            foreach ($places as $place) {
                if (!str_starts_with($place, 'colonne-')) {
                    continue;
                }

                $target = $state->columns[(int) substr($place, 8)];
                $onto = [] === $target ? null : SpiderCard::fromCode($target[\count($target) - 1][0]);
                // Déjà bien placée (sur la carte juste au-dessus, de même couleur) : inutile de la bouger
                $settled = null !== $below && $below[1] && $this->follows(SpiderCard::fromCode($below[0]), $card);

                $score = match (true) {
                    $settled => 0,
                    null === $onto => (null !== $below && !$below[1]) ? 2 : 0,
                    null !== $below && !$below[1] => $onto->suit === $card->suit ? 6 : 5,
                    0 === $row => $onto->suit === $card->suit ? 4 : 3,
                    $onto->suit === $card->suit => 3,
                    default => 0,
                };

                if ($score > $bestScore) {
                    $best = [$code, $place];
                    $bestScore = $score;
                }
            }
        }

        if (null === $best) {
            return false;
        }

        $this->engine->move($state, $best[0], $best[1]);

        return true;
    }

    /**
     * Rien d'utile à jouer : distribuer une pile talon ; si une pile de cartes est vide, y mettre d'abord une carte.
     */
    private function dealOrFill(SpiderState $state): bool
    {
        if ([] === array_filter($state->stock)) {
            return false;
        }

        foreach ($state->columns as $index => $cards) {
            if ([] === $cards) {
                return $this->fill($state, $index);
            }
        }

        foreach ($state->stock as $pile => $cards) {
            if ([] !== $cards) {
                $this->engine->dealStock($state, $pile);

                return true;
            }
        }

        return false;
    }

    /** Une pile vide reçoit la dernière carte de la pile la plus haute (pour pouvoir distribuer). */
    private function fill(SpiderState $state, int $empty): bool
    {
        $from = null;

        foreach ($state->columns as $index => $cards) {
            if (\count($cards) > 1 && (null === $from || \count($cards) > \count($state->columns[$from]))) {
                $from = $index;
            }
        }

        if (null === $from) {
            return false;
        }

        $cards = $state->columns[$from];
        $this->engine->move($state, $cards[\count($cards) - 1][0], 'colonne-'.$empty);

        return true;
    }

    /**
     * @return array{?int, int}
     */
    private function where(SpiderState $state, string $code): array
    {
        foreach ($state->columns as $index => $cards) {
            foreach ($cards as $row => [$held]) {
                if ($held === $code) {
                    return [$index, $row];
                }
            }
        }

        return [null, 0];
    }

    private function follows(SpiderCard $upper, SpiderCard $lower): bool
    {
        return $upper->suit === $lower->suit && $upper->rank === $lower->rank + 1;
    }
}
