<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

use App\Game\Patience\PatienceRules;
use App\Game\Patience\PatienceState;

/**
 * Le solitaire (Klondike) vu d'une partie de patience : donne, robot, fin de grille et rangement automatique.
 * Solitaire : 3 cartes à la fois au talon ; Solitaire harmonie : une seule.
 */
final readonly class SolitaireRules implements PatienceRules
{
    private SolitaireBot $bot;

    public function __construct(
        /** Cartes retournées à chaque clic sur le talon */
        private int $draw = 3,
        private SolitaireEngine $engine = new SolitaireEngine(),
    ) {
        $this->bot = new SolitaireBot($this->engine);
    }

    public function engine(): SolitaireEngine
    {
        return $this->engine;
    }

    /**
     * La donne ; les As et les 2 déjà visibles montent aussitôt sur leur famille (pareil pour tous les joueurs).
     * La liste de ces cartes est gardée : à l'arrivée sur la table, la page les montre partir des piles vers leur famille.
     */
    public function deal(int $seed): SolitaireState
    {
        $state = $this->engine->deal($seed, $this->draw);
        $this->engine->autoPlay($state);

        return $state;
    }

    public function restore(array $data): SolitaireState
    {
        return SolitaireState::fromArray($data);
    }

    public function botPlay(PatienceState $state): void
    {
        $this->bot->play($this->state($state));
    }

    public function finish(PatienceState $state, int $secondsLeft, bool $complete): void
    {
        $this->engine->finish($this->state($state), $secondsLeft, $complete);
    }

    /**
     * Rangement automatique (réglage du joueur, activé par défaut) : les cartes qui ne servent plus montent sur leur famille.
     * Et la fin de partie : quand tout est visible et le talon vide, toutes les cartes montent d'elles-mêmes.
     */
    public function afterMove(PatienceState $state, bool $auto): void
    {
        // La liste ne vaut que pour ce coup : la page fait monter ces cartes l'une après l'autre
        $this->state($state)->auto = [];

        if ($auto) {
            $this->engine->autoPlay($this->state($state));
        }

        // Talon vide, toutes les cartes visibles : la fin se joue toute seule (rangement automatique ou non)
        $this->engine->autoComplete($this->state($state));
    }

    private function state(PatienceState $state): SolitaireState
    {
        \assert($state instanceof SolitaireState);

        return $state;
    }
}
