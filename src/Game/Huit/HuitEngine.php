<?php

declare(strict_types=1);

namespace App\Game\Huit;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * 8 américain. Chaque coup est vérifié ici.
 *
 * - On pose une carte de la couleur demandée ou de la même hauteur que celle du dessus ; sinon on pioche une carte,
 *   qu'on peut poser aussitôt si elle va (sinon on passe).
 * - 8 : se pose sur tout, on choisit la couleur. As : le suivant pioche 2 cartes, sauf s'il pose un As à son tour
 *   (la pénalité passe au suivant, augmentée de 2). Valet : le suivant passe son tour. 10 : on rejoue. 7 : le sens change.
 * - Il faut annoncer « Carte ! » en posant son avant-dernière carte, sinon on pioche 2 cartes.
 * - La manche s'arrête quand un joueur n'a plus de cartes ; les autres comptent ce qui leur reste en main.
 */
final class HuitEngine
{
    public const int FORGOT_PENALTY = 2;
    public const int ACE_PENALTY = 2;

    public function __construct(
        /** Pour remélanger la pile quand la pioche est vide */
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * Donne d'une manche : 8 cartes chacun à 2 joueurs, 7 au-delà ; deux jeux à partir de 5 joueurs.
     * La première carte de la pioche est retournée (sans effet) ; le joueur après le donneur commence.
     */
    public function deal(int $seed, int $players, int $dealer): HuitState
    {
        $state = new HuitState($players, $dealer);
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(HuitCard::deck($players >= 5 ? 2 : 1));
        $codes = array_map(static fn (HuitCard $card): string => $card->code(), $cards);
        $size = 2 === $players ? 8 : 7;

        foreach (range(0, $players - 1) as $position) {
            $state->hands[$position] = array_splice($codes, 0, $size);
        }

        $first = array_pop($codes);
        $state->pile = [$first];
        $state->suit = HuitCard::fromCode($first)->suit;
        $state->stock = $codes;
        $state->turn = ($dealer + 1) % $players;

        return $state;
    }

    /**
     * Poser une carte. Avec un 8, la couleur choisie (sinon celle du 8). « announce » : le joueur a dit « Carte ! ».
     *
     * @throws HuitException
     */
    public function play(HuitState $state, int $position, string $code, ?string $suit = null, bool $announce = false): void
    {
        $this->assertTurn($state, $position);

        if (!\in_array($code, $state->hands[$position], true)) {
            throw new HuitException('Cette carte n’est pas dans votre main.');
        }

        if (null !== $state->drawn && $code !== $state->drawn) {
            throw new HuitException('Après avoir pioché, seule la carte piochée peut être posée.');
        }

        $card = HuitCard::fromCode($code);

        if (!$this->canPlay($state, $card)) {
            throw new HuitException($state->penalty > 0
                ? \sprintf('Posez un As, ou piochez les %d cartes.', $state->penalty)
                : 'Il faut une carte de la couleur demandée ou de la même hauteur (ou un 8).');
        }

        if (null !== $suit && !\in_array($suit, HuitCard::SUITS, true)) {
            throw new HuitException('Couleur inconnue.');
        }

        $state->hands[$position] = array_values(array_diff($state->hands[$position], [$code]));
        $state->pile[] = $code;
        $state->suit = 8 === $card->rank ? ($suit ?? $card->suit) : $card->suit;
        $state->drawn = null;

        if ([] === $state->hands[$position]) {
            $this->endRound($state, $position);

            return;
        }

        // Une seule carte en main sans avoir dit « Carte ! » : 2 cartes de pénalité
        if (1 === \count($state->hands[$position]) && !$announce) {
            $this->take($state, $position, self::FORGOT_PENALTY);
        }

        match ($card->rank) {
            1 => $state->penalty += self::ACE_PENALTY,
            7 => $state->direction = -$state->direction,
            default => null,
        };

        // 10 : on rejoue ; Valet : le suivant passe son tour (à 2 joueurs, on rejoue aussi)
        if (10 === $card->rank) {
            return;
        }

        $this->nextTurn($state, 11 === $card->rank ? 2 : 1);
    }

    /**
     * Piocher : les cartes de pénalité (As) d'un coup, et le tour passe ; sinon une carte, qu'on peut poser aussitôt si elle va.
     *
     * @throws HuitException
     */
    public function draw(HuitState $state, int $position): void
    {
        $this->assertTurn($state, $position);

        if (null !== $state->drawn) {
            throw new HuitException('Vous avez déjà pioché : posez la carte piochée ou passez.');
        }

        if ($state->penalty > 0) {
            $this->take($state, $position, $state->penalty);
            $state->penalty = 0;
            $this->nextTurn($state, 1);

            return;
        }

        $drawn = $this->take($state, $position, 1);

        if ([] === $drawn) {
            // Plus aucune carte à piocher : le tour passe
            $this->nextTurn($state, 1);

            return;
        }

        $state->drawn = $drawn[0];

        // La carte piochée ne va pas : le tour passe tout seul
        if (!$this->canPlay($state, HuitCard::fromCode($state->drawn))) {
            $state->drawn = null;
            $this->nextTurn($state, 1);
        }
    }

    /**
     * Passer après avoir pioché une carte qu'on ne veut pas poser.
     *
     * @throws HuitException
     */
    public function pass(HuitState $state, int $position): void
    {
        $this->assertTurn($state, $position);

        if (null === $state->drawn) {
            throw new HuitException('Piochez d’abord une carte.');
        }

        $state->drawn = null;
        $this->nextTurn($state, 1);
    }

    /**
     * Les cartes de la main qui peuvent être posées maintenant.
     *
     * @return list<string>
     */
    public function playable(HuitState $state, int $position): array
    {
        if ($state->isOver() || $state->turn !== $position) {
            return [];
        }

        $cards = null === $state->drawn ? $state->hands[$position] : [$state->drawn];

        return array_values(array_filter($cards, fn (string $code): bool => $this->canPlay($state, HuitCard::fromCode($code))));
    }

    /**
     * Joue le tour du joueur dont c'est le tour, simplement : temps écoulé, joueur parti ou robot.
     * Il garde ses 8 pour la fin, pose sinon la carte jouable qui coûte le plus, et choisit la couleur qu'il a le plus.
     */
    public function autoPlay(HuitState $state): void
    {
        $position = $state->turn;
        $playable = $this->playable($state, $position);

        if ([] === $playable) {
            if (null !== $state->drawn) {
                $this->pass($state, $position);

                return;
            }

            $this->draw($state, $position);

            if (null !== $state->drawn && $state->turn === $position) {
                $this->play($state, $position, $state->drawn, $this->favouriteSuit($state->hands[$position]), true);
            }

            return;
        }

        usort($playable, static function (string $a, string $b): int {
            $ca = HuitCard::fromCode($a);
            $cb = HuitCard::fromCode($b);

            return [8 === $ca->rank, -$ca->penalty()] <=> [8 === $cb->rank, -$cb->penalty()];
        });

        $this->play($state, $position, $playable[0], $this->favouriteSuit(array_values(array_diff($state->hands[$position], [$playable[0]]))), true);
    }

    // ---------- Interne ----------

    private function canPlay(HuitState $state, HuitCard $card): bool
    {
        if ($state->penalty > 0) {
            return 1 === $card->rank;
        }

        $top = $state->top();

        return 8 === $card->rank || $card->suit === $state->suit || (null !== $top && HuitCard::fromCode($top)->rank === $card->rank);
    }

    /**
     * @param list<string> $hand
     */
    private function favouriteSuit(array $hand): string
    {
        $counts = array_fill_keys(HuitCard::SUITS, 0);

        foreach ($hand as $code) {
            $card = HuitCard::fromCode($code);

            if (8 !== $card->rank) {
                ++$counts[$card->suit];
            }
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Prend des cartes dans la pioche (remélangée à partir de la pile quand elle est vide). Renvoie les cartes prises.
     *
     * @return list<string>
     */
    private function take(HuitState $state, int $position, int $count): array
    {
        $taken = [];

        for ($i = 0; $i < $count; ++$i) {
            if ([] === $state->stock && \count($state->pile) > 1) {
                $top = array_pop($state->pile);
                $state->stock = $this->randomizer->shuffleArray($state->pile);
                $state->pile = [$top];
            }

            if ([] === $state->stock) {
                break;
            }

            $taken[] = array_pop($state->stock);
        }

        $state->hands[$position] = [...$state->hands[$position], ...$taken];

        return $taken;
    }

    private function nextTurn(HuitState $state, int $steps): void
    {
        $state->turn = (($state->turn + $steps * $state->direction) % $state->players + $state->players) % $state->players;
        $state->drawn = null;
    }

    private function endRound(HuitState $state, int $winner): void
    {
        $state->phase = HuitState::OVER;
        $state->winner = $winner;

        foreach ($state->hands as $position => $hand) {
            $state->penalties[$position] = array_sum(array_map(static fn (string $code): int => HuitCard::fromCode($code)->penalty(), $hand));
        }
    }

    /**
     * @throws HuitException
     */
    private function assertTurn(HuitState $state, int $position): void
    {
        if ($state->isOver()) {
            throw new HuitException('La manche est terminée.');
        }

        if ($state->turn !== $position) {
            throw new HuitException('Ce n’est pas votre tour.');
        }
    }
}
