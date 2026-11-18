<?php

declare(strict_types=1);

namespace App\Game\Pyramide;

use App\Game\CardPatience\CardPatienceException;
use App\Game\CardPatience\CardPatienceRules;
use App\Game\CardPatience\CardPatienceState;
use App\Game\Patience\PatienceState;
use App\Game\Solitaire\SolitaireCard;

/**
 * Pyramide : on enlève deux cartes libres dont les valeurs font 13 (As 1, Valet 11, Dame 12) ; le Roi (13) part seul.
 * La carte du dessus de la défausse se joue aussi. Le talon se retourne une carte à la fois, et repasse 2 fois.
 * Le but : vider la pyramide. 1 000 points par carte de la pyramide, 200 par carte de la défausse.
 * Coup : {"card": "coeur-6", "to": "pique-7"} (la carte qui fait 13 avec elle), ou {"card": "trefle-roi", "to": "seul"}.
 */
final class PyramideRules extends CardPatienceRules
{
    public const int WASTE_CARD = 200;

    protected function newState(int $seed, array $cards): PyramideState
    {
        $state = new PyramideState($seed);
        $state->pyramid = \array_slice($cards, 0, 28);
        $state->stock = \array_slice($cards, 28);

        return $state;
    }

    public function restore(array $data): PyramideState
    {
        return PyramideState::fromArray($data);
    }

    protected function play(CardPatienceState $state, string $card, string $to): void
    {
        $state = $this->pyramide($state);
        $free = $this->free($state);

        if (!isset($free[$card])) {
            throw new CardPatienceException('Cette carte est encore couverte.');
        }

        if ('seul' === $to) {
            if (13 !== SolitaireCard::fromCode($card)->rank) {
                throw new CardPatienceException('Seul un Roi part tout seul.');
            }

            $this->remove($state, $card, $free[$card]);

            return;
        }

        if (!isset($free[$to]) || $to === $card) {
            throw new CardPatienceException('Choisissez une autre carte libre.');
        }

        if (13 !== SolitaireCard::fromCode($card)->rank + SolitaireCard::fromCode($to)->rank) {
            throw new CardPatienceException('Les deux cartes doivent faire 13 (Valet 11, Dame 12, Roi 13 tout seul).');
        }

        $this->remove($state, $card, $free[$card]);
        $this->remove($state, $to, $free[$to]);
    }

    protected function turnStock(CardPatienceState $state): void
    {
        $state = $this->pyramide($state);

        if ([] !== $state->stock) {
            $state->waste[] = array_pop($state->stock);

            return;
        }

        $state->stock = array_reverse($state->waste);
        $state->waste = [];
        ++$state->passes;
    }

    public function canTurnStock(CardPatienceState $state): bool
    {
        $state = $this->pyramide($state);

        return [] !== $state->stock || ([] !== $state->waste && $state->passes < PyramideState::MAX_PASSES);
    }

    public function targets(CardPatienceState $state): array
    {
        $state = $this->pyramide($state);
        $free = array_keys($this->free($state));
        $targets = [];

        foreach ($free as $card) {
            $rank = SolitaireCard::fromCode($card)->rank;
            $places = 13 === $rank ? ['seul'] : array_values(array_filter($free, static fn (string $other): bool => $other !== $card && SolitaireCard::fromCode($other)->rank + $rank === 13));

            if ([] !== $places) {
                $targets[$card] = $places;
            }
        }

        return $targets;
    }

    public function cards(CardPatienceState $state): array
    {
        $state = $this->pyramide($state);

        return [
            'pyramid' => $state->pyramid,
            'free' => array_values(array_filter(array_keys($state->pyramid), $state->isFree(...))),
            'stock' => \count($state->stock),
            'waste' => [] === $state->waste ? null : $state->waste[array_key_last($state->waste)],
            'passesLeft' => PyramideState::MAX_PASSES - $state->passes,
            'canDraw' => $this->canTurnStock($state),
        ];
    }

    /**
     * Les cartes qu'on peut jouer : celles de la pyramide que rien ne couvre (avec leur place), et le dessus de la défausse (-1).
     *
     * @return array<string, int>
     */
    private function free(PyramideState $state): array
    {
        $free = [];

        foreach ($state->pyramid as $index => $card) {
            if (null !== $card && $state->isFree($index)) {
                $free[$card] = $index;
            }
        }

        if ([] !== $state->waste) {
            $free[$state->waste[array_key_last($state->waste)]] = -1;
        }

        return $free;
    }

    private function remove(PyramideState $state, string $card, int $index): void
    {
        if (-1 === $index) {
            array_pop($state->waste);
            $state->score += self::WASTE_CARD;

            return;
        }

        $state->pyramid[$index] = null;
        $this->scoreCard($state);
    }

    private function pyramide(PatienceState $state): PyramideState
    {
        \assert($state instanceof PyramideState);

        return $state;
    }
}
