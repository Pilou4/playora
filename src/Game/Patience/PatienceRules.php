<?php

declare(strict_types=1);

namespace App\Game\Patience;

/**
 * Ce qu'une partie de patience (manches, temps, robots, abandon) doit savoir d'un jeu : donner, relire une grille,
 * faire jouer un robot, terminer une grille. Les coups propres au jeu passent par son moteur.
 */
interface PatienceRules
{
    /** La même graine donne la même donne à tous les joueurs. */
    public function deal(int $seed): PatienceState;

    /**
     * @param array<string, mixed> $data
     */
    public function restore(array $data): PatienceState;

    /** Un robot (mode test) joue sa grille d'un trait et la termine. */
    public function botPlay(PatienceState $state): void;

    /** Fin de la grille : bonus selon le jeu (temps restant, tout rangé…). */
    public function finish(PatienceState $state, int $secondsLeft, bool $complete): void;

    /** Après chaque coup du joueur (solitaire : rangement automatique). */
    public function afterMove(PatienceState $state, bool $auto): void;
}
