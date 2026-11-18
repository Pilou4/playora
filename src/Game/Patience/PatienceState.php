<?php

declare(strict_types=1);

namespace App\Game\Patience;

/**
 * La grille d'un joueur dans un jeu de patience, vue de la partie : son score, si elle est finie, si tout est rangé.
 */
interface PatienceState
{
    public function score(): int;

    /** Toutes les cartes rangées. */
    public function isComplete(): bool;

    /** Cartes rangées sur les familles (sur 52 au solitaire, 104 au Spider). */
    public function sortedCards(): int;

    /** Nombre de cartes du jeu. */
    public function cardCount(): int;

    /** Plus aucun coup possible (tout rangé, « Arrêter » ou temps écoulé). */
    public function isFinished(): bool;

    /**
     * L'état complet, enregistré en JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
