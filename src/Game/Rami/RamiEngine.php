<?php

declare(strict_types=1);

namespace App\Game\Rami;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Rami (règles françaises « Rami 51 »). Chaque coup est vérifié ici.
 *
 * - 108 cartes (2 jeux de 52 et 4 jokers), 13 cartes chacun ; le joueur après le donneur en reçoit 14 et commence en défaussant.
 * - À son tour : prendre la carte de la pioche ou celle du dessus de la défausse, poser des combinaisons ou compléter
 *   celles qui sont posées, reprendre un joker, puis défausser une carte.
 * - Première pose : au moins 51 points d'un coup, dont au moins une suite ; avant, on ne complète pas les combinaisons.
 * - La carte prise dans la défausse doit être posée pendant le même tour (ou rendue).
 * - La manche s'arrête quand un joueur n'a plus de cartes. Les autres comptent les cartes qui leur restent ;
 *   tout poser d'un coup sans avoir rien posé avant (« rami sec ») double leurs pénalités.
 */
final class RamiEngine
{
    public const int HAND = 13;
    public const int FIRST_MELD = 51;

    public function __construct(
        /** Pour remélanger la défausse quand la pioche est vide */
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * Donne d'une manche d'après une graine. Le joueur après le donneur reçoit 14 cartes et commence (en défaussant).
     */
    public function deal(int $seed, int $players, int $dealer): RamiState
    {
        $state = new RamiState($players, $dealer);
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(RamiCard::deck());
        $codes = array_map(static fn (RamiCard $card): string => $card->code(), $cards);
        $first = ($dealer + 1) % $players;

        foreach (range(0, $players - 1) as $position) {
            $state->hands[$position] = array_splice($codes, 0, self::HAND + ($position === $first ? 1 : 0));
        }

        $state->stock = $codes;
        $this->startTurn($state, $first);
        $state->phase = RamiState::PLAY;

        return $state;
    }

    /**
     * Prendre la carte du dessus de la pioche. Pioche vide : la défausse (sauf sa carte du dessus) est remélangée.
     *
     * @throws RamiException
     */
    public function draw(RamiState $state, int $position): void
    {
        $this->assertTurn($state, $position, RamiState::DRAW);

        if ([] === $state->stock) {
            $this->refillStock($state);
        }

        if ([] === $state->stock) {
            // Plus aucune carte à prendre : la manche s'arrête sans gagnant
            $this->endRound($state, null);

            return;
        }

        $state->hands[$position][] = array_pop($state->stock);
        $state->phase = RamiState::PLAY;
    }

    /**
     * Prendre la carte du dessus de la défausse : il faudra la poser pendant ce tour.
     *
     * @throws RamiException
     */
    public function takeDiscard(RamiState $state, int $position): void
    {
        $this->assertTurn($state, $position, RamiState::DRAW);

        if ([] === $state->discard) {
            throw new RamiException('La défausse est vide.');
        }

        $code = array_pop($state->discard);
        $state->hands[$position][] = $code;
        $state->taken = $code;
        $state->phase = RamiState::PLAY;
    }

    /**
     * Rendre la carte prise dans la défausse (tant qu'elle n'est pas posée) : on reprend alors dans la pioche.
     *
     * @throws RamiException
     */
    public function giveBack(RamiState $state, int $position): void
    {
        $this->assertTurn($state, $position, RamiState::PLAY);

        if (null === $state->taken) {
            throw new RamiException('Vous n’avez pas pris de carte dans la défausse.');
        }

        $this->removeFromHand($state, $position, [$state->taken]);
        $state->discard[] = $state->taken;
        $state->taken = null;
        $state->phase = RamiState::DRAW;
    }

    /**
     * Poser une ou plusieurs combinaisons. La première pose doit faire au moins 51 points, dont une suite.
     *
     * @param list<list<string>> $groups
     *
     * @throws RamiException
     */
    public function meld(RamiState $state, int $position, array $groups): void
    {
        $this->assertTurn($state, $position, RamiState::PLAY);

        if ([] === $groups) {
            throw new RamiException('Choisissez les cartes à poser.');
        }

        $codes = array_merge(...$groups);
        $this->assertInHand($state, $position, $codes);
        $melds = array_map(static fn (array $group): RamiMeld => RamiMeld::build($group), $groups);

        if (!$state->opened[$position]) {
            $points = array_sum(array_map(static fn (RamiMeld $meld): int => $meld->points, $melds));
            $runs = array_filter($melds, static fn (RamiMeld $meld): bool => RamiMeld::RUN === $meld->kind);

            if ($points < self::FIRST_MELD) {
                throw new RamiException(\sprintf('Première pose : il faut au moins %d points (vous en avez %d).', self::FIRST_MELD, $points));
            }

            if ([] === $runs) {
                throw new RamiException('Première pose : il faut au moins une suite.');
            }

            $state->opened[$position] = true;
        }

        $this->removeFromHand($state, $position, $codes);

        foreach ($melds as $meld) {
            $state->melds[] = ['owner' => $position, 'meld' => $meld];
        }

        $this->afterPlay($state, $position, $codes);
    }

    /**
     * Compléter une combinaison posée (la sienne ou celle d'un autre joueur) avec une carte de sa main.
     *
     * @throws RamiException
     */
    public function layOff(RamiState $state, int $position, string $code, int $meld): void
    {
        $this->assertTurn($state, $position, RamiState::PLAY);
        $this->assertOpened($state, $position);
        $this->assertInHand($state, $position, [$code]);
        $entry = $state->melds[$meld] ?? throw new RamiException('Combinaison inconnue.');

        try {
            $state->melds[$meld]['meld'] = $entry['meld']->with($code);
        } catch (RamiException) {
            throw new RamiException('Cette carte ne complète pas cette combinaison.');
        }

        $this->removeFromHand($state, $position, [$code]);
        $this->afterPlay($state, $position, [$code]);
    }

    /**
     * Reprendre un joker posé en mettant à sa place la carte qu'il remplace.
     *
     * @throws RamiException
     */
    public function swapJoker(RamiState $state, int $position, string $code, int $meld): void
    {
        $this->assertTurn($state, $position, RamiState::PLAY);
        $this->assertOpened($state, $position);
        $this->assertInHand($state, $position, [$code]);
        $entry = $state->melds[$meld] ?? throw new RamiException('Combinaison inconnue.');
        $card = RamiCard::fromCode($code);
        $joker = $card->isJoker() ? null : $entry['meld']->jokerFor($card);

        if (null === $joker) {
            throw new RamiException('Aucun joker de cette combinaison ne remplace cette carte.');
        }

        $codes = array_map(static fn (string $held): string => $held === $joker ? $code : $held, $entry['meld']->codes());
        $state->melds[$meld]['meld'] = RamiMeld::build($codes);
        $this->removeFromHand($state, $position, [$code]);
        $state->hands[$position][] = $joker;
        $this->afterPlay($state, $position, [$code]);
    }

    /**
     * Défausser une carte : le tour passe au joueur suivant, ou la manche s'arrête si la main est vide.
     *
     * @throws RamiException
     */
    public function discard(RamiState $state, int $position, string $code): void
    {
        $this->assertTurn($state, $position, RamiState::PLAY);
        $this->assertInHand($state, $position, [$code]);

        if (null !== $state->taken) {
            throw new RamiException('Posez d’abord la carte prise dans la défausse, ou rendez-la.');
        }

        $this->removeFromHand($state, $position, [$code]);
        $state->discard[] = $code;

        if ([] === $state->hands[$position]) {
            $this->endRound($state, $position);

            return;
        }

        $this->startTurn($state, ($position + 1) % $state->players);
    }

    /**
     * Temps écoulé (ou joueur parti) : le serveur joue pour lui le plus simplement possible.
     * Il rend la carte prise dans la défausse, pioche, puis défausse la carte la moins utile.
     */
    public function autoPlay(RamiState $state): void
    {
        $position = $state->turn;

        if (RamiState::PLAY === $state->phase && null !== $state->taken) {
            $this->giveBack($state, $position);
        }

        if (RamiState::DRAW === $state->phase) {
            $this->draw($state, $position);
        }

        if (RamiState::PLAY === $state->phase) {
            $this->discard($state, $position, RamiHand::worst($state->hands[$position]));
        }
    }

    // ---------- Interne ----------

    /**
     * @param list<string> $codes les cartes qui viennent d'être posées
     */
    private function afterPlay(RamiState $state, int $position, array $codes): void
    {
        if (null !== $state->taken && \in_array($state->taken, $codes, true)) {
            $state->taken = null;
        }

        // Plus de cartes : la manche s'arrête (sans défausse finale)
        if ([] === $state->hands[$position]) {
            $this->endRound($state, $position);
        }
    }

    private function endRound(RamiState $state, ?int $winner): void
    {
        $state->phase = RamiState::OVER;
        $state->winner = $winner;
        $state->ramiSec = null !== $winner && !$state->openedBefore;
        $factor = $state->ramiSec ? 2 : 1;

        foreach ($state->hands as $position => $hand) {
            $points = array_sum(array_map(static fn (string $code): int => RamiCard::fromCode($code)->penalty(), $hand));
            $state->penalties[$position] = $position === $winner ? 0 : $points * $factor;
        }
    }

    private function startTurn(RamiState $state, int $position): void
    {
        $state->turn = $position;
        $state->phase = RamiState::DRAW;
        $state->taken = null;
        $state->openedBefore = $state->opened[$position];
    }

    /** La défausse, sauf sa carte du dessus, est remélangée et redevient la pioche. */
    private function refillStock(RamiState $state): void
    {
        $top = array_pop($state->discard);
        $state->stock = $this->randomizer->shuffleArray($state->discard);
        $state->discard = null === $top ? [] : [$top];
    }

    /**
     * @param list<string> $codes
     */
    private function removeFromHand(RamiState $state, int $position, array $codes): void
    {
        $state->hands[$position] = array_values(array_diff($state->hands[$position], $codes));
    }

    /**
     * @param list<string> $codes
     *
     * @throws RamiException
     */
    private function assertInHand(RamiState $state, int $position, array $codes): void
    {
        if (\count($codes) !== \count(array_unique($codes))) {
            throw new RamiException('Carte en double.');
        }

        foreach ($codes as $code) {
            if (!\in_array($code, $state->hands[$position], true)) {
                throw new RamiException('Cette carte n’est pas dans votre main.');
            }
        }
    }

    /**
     * @throws RamiException
     */
    private function assertOpened(RamiState $state, int $position): void
    {
        if (!$state->opened[$position]) {
            throw new RamiException(\sprintf('Faites d’abord votre première pose (%d points, dont une suite).', self::FIRST_MELD));
        }
    }

    /**
     * @throws RamiException
     */
    private function assertTurn(RamiState $state, int $position, string $phase): void
    {
        if ($state->isOver()) {
            throw new RamiException('La manche est terminée.');
        }

        if ($state->turn !== $position) {
            throw new RamiException('Ce n’est pas votre tour.');
        }

        if ($state->phase !== $phase) {
            throw new RamiException(RamiState::DRAW === $state->phase ? 'Prenez d’abord une carte (pioche ou défausse).' : 'Vous avez déjà pris une carte : posez ou défaussez.');
        }
    }
}
