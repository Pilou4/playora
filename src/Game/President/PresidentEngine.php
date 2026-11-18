<?php

declare(strict_types=1);

namespace App\Game\President;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Président (règles de base). Chaque coup est vérifié ici.
 *
 * - 3 à 7 joueurs, 52 cartes toutes distribuées ; du plus faible au plus fort : 3, 4… Roi, As, 2.
 * - Celui qui a la main pose 1 à 4 cartes de même valeur ; les suivants posent autant de cartes, d'une valeur
 *   strictement plus forte, ou passent (un joueur qui a passé peut rejouer à son tour suivant dans le même pli).
 * - Quand tous les autres ont passé, le pli est écarté et le dernier à avoir posé reprend la main
 *   (s'il n'a plus de cartes : le joueur suivant).
 * - Le premier à n'avoir plus de cartes est Président, le dernier Pauvre ; dès 4 joueurs, le deuxième est
 *   Vice-président et l'avant-dernier Vice-pauvre. 1 point par joueur battu.
 * - Manche suivante : le Pauvre donne ses 2 meilleures cartes au Président, qui lui en rend 2 de son choix ;
 *   le Vice-pauvre et le Vice-président font de même avec 1 carte. Le Pauvre commence.
 *   À la première manche, c'est le joueur qui a la Dame de cœur.
 */
final class PresidentEngine
{
    public const string FIRST_CARD = 'coeur-dame';
    public const int MAX_CARDS = 4;

    /**
     * Donne d'une manche. Avec l'ordre de fin de la manche précédente, les titres et l'échange de cartes.
     *
     * @param list<int>|null $previousOrder
     */
    public function deal(int $seed, int $players, ?array $previousOrder = null): PresidentState
    {
        $state = new PresidentState($players);
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(PresidentCard::deck());

        foreach ($cards as $index => $card) {
            $state->hands[$index % $players][] = $card->code();
        }

        foreach ($state->hands as $position => $hand) {
            $state->hands[$position] = PresidentCard::sort($hand);
        }

        if (null === $previousOrder || \count($previousOrder) !== $players) {
            foreach ($state->hands as $position => $hand) {
                if (\in_array(self::FIRST_CARD, $hand, true)) {
                    $state->turn = $position;
                }
            }

            return $state;
        }

        $state->roles = PresidentState::rolesFor($previousOrder);
        $poor = $previousOrder[$players - 1];
        $state->turn = $poor;
        $state->phase = PresidentState::EXCHANGE;
        $this->giveBest($state, $poor, $previousOrder[0], 2);

        if ($players >= 4) {
            $this->giveBest($state, $previousOrder[$players - 2], $previousOrder[1], 1);
        }

        return $state;
    }

    /**
     * Échange : le Président (2 cartes) ou le Vice-président (1 carte) rend les cartes de son choix.
     *
     * @param list<string> $cards
     *
     * @throws PresidentException
     */
    public function give(PresidentState $state, int $position, array $cards): void
    {
        if (PresidentState::EXCHANGE !== $state->phase) {
            throw new PresidentException('L’échange des cartes est terminé.');
        }

        $count = $state->toGive[$position] ?? 0;

        if (0 === $count) {
            throw new PresidentException('Vous n’avez pas de carte à donner.');
        }

        $cards = array_values(array_unique($cards));

        if (\count($cards) !== $count) {
            throw new PresidentException(2 === $count ? 'Choisissez 2 cartes à donner.' : 'Choisissez 1 carte à donner.');
        }

        $this->assertInHand($state, $position, $cards);
        $receiver = (int) $state->giveTo[$position];
        $this->move($state, $position, $receiver, $cards);
        $state->toGive[$position] = 0;

        if (0 === array_sum($state->toGive)) {
            $state->phase = PresidentState::PLAY;
        }
    }

    /**
     * Poser une ou plusieurs cartes de même valeur.
     *
     * @param list<string> $cards
     *
     * @throws PresidentException
     */
    public function play(PresidentState $state, int $position, array $cards): void
    {
        $this->assertTurn($state, $position);
        $cards = array_values(array_unique($cards));

        if ([] === $cards) {
            throw new PresidentException('Choisissez les cartes à poser.');
        }

        if (\count($cards) > self::MAX_CARDS) {
            throw new PresidentException('On pose 4 cartes au plus.');
        }

        $this->assertInHand($state, $position, $cards);
        $ranks = array_unique(array_map(static fn (string $code): string => PresidentCard::fromCode($code)->rank, $cards));

        if (1 !== \count($ranks)) {
            throw new PresidentException('Posez des cartes de même valeur.');
        }

        $last = $state->lastPlay();

        if (null !== $last) {
            $need = \count($last['cards']);
            $top = PresidentCard::fromCode($last['cards'][0]);

            if (\count($cards) !== $need) {
                throw new PresidentException(\sprintf('Il faut poser %s, comme la pose à battre.', $this->countLabel($need)));
            }

            if (PresidentCard::powerOf($cards[0]) <= $top->power()) {
                throw new PresidentException(\sprintf('Il faut des cartes plus fortes que %s.', $this->rankLabel($top->rank, $need)));
            }
        }

        $state->hands[$position] = array_values(array_diff($state->hands[$position], $cards));
        $state->trick[] = ['p' => $position, 'cards' => PresidentCard::sort($cards)];
        $state->passed = [];
        $state->cleared = [];

        if ([] === $state->hands[$position]) {
            $state->order[] = $position;
        }

        if (\count($state->active()) <= 1) {
            $this->endRound($state);

            return;
        }

        $state->turn = $this->nextActive($state, $position);
    }

    /**
     * Passer son tour.
     *
     * @throws PresidentException
     */
    public function pass(PresidentState $state, int $position): void
    {
        $this->assertTurn($state, $position);

        if (null === $state->lastPlay()) {
            throw new PresidentException('Vous avez la main : posez une ou plusieurs cartes.');
        }

        $state->passed[] = $position;

        if (!$this->endTrickIfDone($state)) {
            $state->turn = $this->nextActive($state, $position);
        }
    }

    /**
     * Les valeurs que le joueur peut poser maintenant (avec le nombre de cartes à poser : null quand il a la main).
     *
     * @return array{ranks: list<string>, count: ?int}
     */
    public function options(PresidentState $state, int $position): array
    {
        if (PresidentState::PLAY !== $state->phase || $state->turn !== $position) {
            return ['ranks' => [], 'count' => null];
        }

        $last = $state->lastPlay();
        $need = null === $last ? null : \count($last['cards']);
        $top = null === $last ? -1 : PresidentCard::powerOf($last['cards'][0]);
        $ranks = [];

        foreach ($this->groups($state->hands[$position]) as $rank => $codes) {
            if (null === $need || (\count($codes) >= $need && PresidentCard::fromCode($codes[0])->power() > $top)) {
                $ranks[] = (string) $rank;
            }
        }

        return ['ranks' => $ranks, 'count' => $need];
    }

    /**
     * Joue le tour du joueur dont c'est le tour, simplement : temps écoulé, joueur parti ou robot.
     * Avec la main, il pose sa plus faible valeur (toutes ses cartes de cette valeur) ; sinon, la plus faible valeur
     * qui bat la pose, sans casser une paire ou un brelan s'il peut l'éviter, et garde ses 2 tant qu'il a du jeu.
     */
    public function autoPlay(PresidentState $state): void
    {
        if (PresidentState::EXCHANGE === $state->phase) {
            $this->autoExchange($state);

            return;
        }

        if (PresidentState::PLAY !== $state->phase) {
            return;
        }

        $position = $state->turn;
        $groups = $this->groups($state->hands[$position]);
        $last = $state->lastPlay();

        if (null === $last) {
            $this->play($state, $position, reset($groups) ?: []);

            return;
        }

        $need = \count($last['cards']);
        $top = PresidentCard::powerOf($last['cards'][0]);
        $exact = null;
        $broken = null;

        foreach ($groups as $codes) {
            if (\count($codes) < $need || PresidentCard::fromCode($codes[0])->power() <= $top) {
                continue;
            }

            if (\count($codes) === $need) {
                $exact ??= $codes;
            } else {
                $broken ??= \array_slice($codes, 0, $need);
            }
        }

        $choice = $exact ?? $broken;

        // Les 2 se gardent pour la fin, tant qu'il reste d'autres cartes à jouer
        if (null !== $choice && '2' === PresidentCard::fromCode($choice[0])->rank && \count($state->hands[$position]) > $need + 2) {
            $choice = null;
        }

        null === $choice ? $this->pass($state, $position) : $this->play($state, $position, $choice);
    }

    /**
     * Échange : les cartes que les joueurs doivent encore rendre partent d'office (les plus faibles).
     */
    public function autoExchange(PresidentState $state): void
    {
        foreach ($state->toGive as $position => $count) {
            if ($count > 0 && PresidentState::EXCHANGE === $state->phase) {
                $this->give($state, $position, \array_slice($state->hands[$position], 0, $count));
            }
        }
    }

    // ---------- Interne ----------

    /**
     * Cartes de la main regroupées par valeur, de la plus faible à la plus forte.
     *
     * @param list<string> $hand
     *
     * @return array<string, list<string>>
     */
    private function groups(array $hand): array
    {
        $groups = [];

        foreach (PresidentCard::sort($hand) as $code) {
            $groups[PresidentCard::fromCode($code)->rank][] = $code;
        }

        return $groups;
    }

    /** Les meilleures cartes du donneur passent au receveur (échange d'office du Pauvre et du Vice-pauvre). */
    private function giveBest(PresidentState $state, int $from, int $to, int $count): void
    {
        $this->move($state, $from, $to, \array_slice($state->hands[$from], -$count));
        $state->toGive[$to] = $count;
        $state->giveTo[$to] = $from;
    }

    /**
     * @param list<string> $cards
     */
    private function move(PresidentState $state, int $from, int $to, array $cards): void
    {
        $state->hands[$from] = array_values(array_diff($state->hands[$from], $cards));
        $state->hands[$to] = PresidentCard::sort([...$state->hands[$to], ...$cards]);
        $state->given[$from] = [...$state->given[$from], ...$cards];
        $state->received[$to] = [...$state->received[$to], ...$cards];
    }

    /**
     * Tous les autres joueurs encore en jeu ont passé depuis la dernière pose : le pli est écarté et la main revient
     * au dernier à avoir posé (ou au joueur suivant s'il n'a plus de cartes).
     */
    private function endTrickIfDone(PresidentState $state): bool
    {
        $last = $state->lastPlay();

        if (null === $last) {
            return false;
        }

        $leader = $last['p'];
        $others = array_diff($state->active(), [$leader]);

        if ([] !== array_diff($others, $state->passed)) {
            return false;
        }

        $state->cleared = $state->trick;
        $state->trick = [];
        $state->passed = [];
        $state->turn = [] === $state->hands[$leader] ? $this->nextActive($state, $leader) : $leader;

        return true;
    }

    private function nextActive(PresidentState $state, int $from): int
    {
        for ($step = 1; $step <= $state->players; ++$step) {
            $position = ($from + $step) % $state->players;

            if ([] !== $state->hands[$position]) {
                return $position;
            }
        }

        return $from;
    }

    private function endRound(PresidentState $state): void
    {
        foreach ($state->active() as $position) {
            $state->order[] = $position;
        }

        $state->phase = PresidentState::OVER;
        $state->cleared = $state->trick;
        $state->trick = [];
        $state->passed = [];
        $state->points = array_fill(0, $state->players, 0);

        foreach ($state->order as $rank => $position) {
            $state->points[$position] = $state->players - 1 - $rank;
        }
    }

    /**
     * @param list<string> $cards
     *
     * @throws PresidentException
     */
    private function assertInHand(PresidentState $state, int $position, array $cards): void
    {
        foreach ($cards as $code) {
            PresidentCard::fromCode($code);

            if (!\in_array($code, $state->hands[$position], true)) {
                throw new PresidentException('Cette carte n’est pas dans votre main.');
            }
        }
    }

    /**
     * @throws PresidentException
     */
    private function assertTurn(PresidentState $state, int $position): void
    {
        if ($state->isOver()) {
            throw new PresidentException('La manche est terminée.');
        }

        if (PresidentState::EXCHANGE === $state->phase) {
            throw new PresidentException('Attendez la fin de l’échange des cartes.');
        }

        if ($state->turn !== $position) {
            throw new PresidentException('Ce n’est pas votre tour.');
        }
    }

    private function countLabel(int $count): string
    {
        return match ($count) {
            1 => '1 carte',
            2 => '2 cartes (une paire)',
            3 => '3 cartes (un brelan)',
            default => '4 cartes (un carré)',
        };
    }

    /** « le 9 », « les 9 », « la Dame », « les Dames ». */
    private function rankLabel(string $rank, int $count): string
    {
        if ($count > 1) {
            return 'les '.PresidentCard::rankPlural($rank);
        }

        return match ($rank) {
            'dame' => 'la Dame',
            'valet' => 'le Valet',
            'roi' => 'le Roi',
            'as' => 'l’As',
            default => 'le '.$rank,
        };
    }
}
