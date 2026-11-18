<?php

declare(strict_types=1);

namespace App\Game\CardPatience;

use App\Game\Patience\PatienceRules;
use App\Game\Patience\PatienceState;
use App\Game\Solitaire\SolitaireCard;
use App\Game\Solitaire\SolitaireScore;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le moteur commun du FreeCell, de la Pyramide et du Golf solitaire : donne mélangée par la graine, coups (déplacer une
 * carte, retourner le talon), « Retour », fin de grille et robot (mode test). Barème du solitaire : 1 000 points
 * par carte rangée, 10 000 si tout est rangé, −1 000 par retour, bonus du temps restant à la fin.
 */
abstract class CardPatienceRules implements PatienceRules
{
    public const int CARD = 1000;
    public const int COMPLETE = 10000;
    public const int UNDO = -1000;

    /** Nouvelle grille, d'après les 52 cartes mélangées. @param list<string> $cards */
    abstract protected function newState(int $seed, array $cards): CardPatienceState;

    /**
     * Une carte va à une place (« colonne-3 », « cellule-0 », « famille », « pile », ou une autre carte à la Pyramide).
     *
     * @throws CardPatienceException
     */
    abstract protected function play(CardPatienceState $state, string $card, string $to): void;

    /**
     * Clic sur le talon (Pyramide, Golf solitaire).
     *
     * @throws CardPatienceException
     */
    protected function turnStock(CardPatienceState $state): void
    {
        throw new CardPatienceException('Pas de talon dans ce jeu.');
    }

    /**
     * Les cartes jouables et leurs places possibles (pour le glisser-déposer et le clic).
     *
     * @return array<string, list<string>>
     */
    abstract public function targets(CardPatienceState $state): array;

    /**
     * Ce que la page dessine de la grille (sans les cartes cachées), en plus de « targets ».
     *
     * @return array<string, mixed>
     */
    abstract public function cards(CardPatienceState $state): array;

    public function canTurnStock(CardPatienceState $state): bool
    {
        return false;
    }

    public function deal(int $seed): CardPatienceState
    {
        $cards = (new Randomizer(new Xoshiro256StarStar($seed)))->shuffleArray(SolitaireCard::deck());

        return $this->newState($seed, array_map(static fn (SolitaireCard $card): string => $card->code(), $cards));
    }

    /**
     * @throws CardPatienceException
     */
    public function move(CardPatienceState $state, string $card, string $to): void
    {
        $this->assertPlaying($state);
        $before = $state->board();
        $this->play($state, $card, $to);
        $this->remember($state, $before);
        ++$state->moves;
        $this->checkComplete($state);
    }

    /**
     * @throws CardPatienceException
     */
    public function draw(CardPatienceState $state): void
    {
        $this->assertPlaying($state);

        if (!$this->canTurnStock($state)) {
            throw new CardPatienceException('Le talon est épuisé.');
        }

        $before = $state->board();
        $this->turnStock($state);
        $this->remember($state, $before);
        ++$state->moves;
    }

    /**
     * @throws CardPatienceException
     */
    public function undo(CardPatienceState $state): void
    {
        $this->assertPlaying($state);
        $board = array_pop($state->history) ?? throw new CardPatienceException('Rien à annuler.');
        $state->restore($board);
        $state->score += self::UNDO;
        ++$state->undos;
    }

    public function finish(PatienceState $state, int $secondsLeft, bool $complete): void
    {
        $state = $this->state($state);
        $secondsLeft = max(0, $secondsLeft);
        $bonus = $complete
            ? $secondsLeft * SolitaireScore::TIME_BONUS_PER_SECOND
            : $secondsLeft * SolitaireScore::STOP_BONUS_PER_SECOND;
        $state->score += $bonus;
        $state->bonus = $bonus;
        $state->finished = true;
        $state->history = [];
    }

    public function afterMove(PatienceState $state, bool $auto): void
    {
    }

    /** Robot (mode test) : le premier coup possible, sinon le talon, jusqu'à ne plus rien pouvoir faire. */
    public function botPlay(PatienceState $state): void
    {
        $state = $this->state($state);

        for ($turn = 0; $turn < 400 && !$state->isFinished(); ++$turn) {
            $targets = $this->targets($state);

            try {
                if ([] !== $targets) {
                    $card = array_key_first($targets);
                    $this->move($state, $card, $targets[$card][0]);
                } elseif ($this->canTurnStock($state)) {
                    $this->draw($state);
                } else {
                    break;
                }
            } catch (CardPatienceException) {
                break;
            }
        }

        if (!$state->isFinished()) {
            $this->finish($state, 0, $state->isComplete());
        }
    }

    /**
     * Le détail du score, pour l'écran de résultat.
     *
     * @return list<array{label: string, value: string}>
     */
    public function details(CardPatienceState $state, int $secondsLeft): array
    {
        return [
            ['label' => 'Cartes rangées', 'value' => $state->sortedCards().' / '.$state->cardCount()],
            ['label' => 'Temps restant', 'value' => \sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60)],
            ['label' => 'Bonus de fin', 'value' => '+ '.number_format($state->bonus, 0, ',', ' ').' points'],
            ['label' => 'Retours', 'value' => $state->undos.' fois'],
        ];
    }

    protected function state(PatienceState $state): CardPatienceState
    {
        \assert($state instanceof CardPatienceState);

        return $state;
    }

    /** Une carte rangée : 1 000 points. */
    protected function scoreCard(CardPatienceState $state, int $cards = 1): void
    {
        $state->score += $cards * self::CARD;
    }

    /**
     * @throws CardPatienceException
     */
    protected function assertPlaying(CardPatienceState $state): void
    {
        if ($state->isFinished()) {
            throw new CardPatienceException('Votre partie est terminée.');
        }
    }

    /**
     * @param array<string, mixed> $before
     */
    private function remember(CardPatienceState $state, array $before): void
    {
        $state->history[] = $before;

        if (\count($state->history) > CardPatienceState::MAX_UNDO) {
            array_shift($state->history);
        }
    }

    /** Tout est rangé : bonus de 10 000 points (la partie se termine ensuite avec le temps restant). */
    private function checkComplete(CardPatienceState $state): void
    {
        if ($state->isComplete()) {
            $state->score += self::COMPLETE;
        }
    }
}
