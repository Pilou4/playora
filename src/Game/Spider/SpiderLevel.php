<?php

declare(strict_types=1);

namespace App\Game\Spider;

/**
 * Les trois Spider (règles GameDuell) : le nombre de couleurs, de piles Joker et le barème changent.
 * - Débutant : 104 cartes d'une seule couleur (pique), 1 Joker.
 * - Spider : 2 couleurs (pique et cœur), 1 Joker.
 * - Pro : 4 couleurs, 2 Jokers, barème plus fort.
 */
enum SpiderLevel: string
{
    case Beginner = 'debutant';
    case Classic = 'classique';
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Spider débutant',
            self::Classic => 'Spider',
            self::Pro => 'Spider Pro',
        };
    }

    /**
     * Couleurs du jeu : 104 cartes en tout, soit 8, 4 ou 2 fois chacune des 13 cartes d'une couleur.
     *
     * @return list<string>
     */
    public function suits(): array
    {
        return match ($this) {
            self::Beginner => ['pique'],
            self::Classic => ['pique', 'coeur'],
            self::Pro => ['pique', 'coeur', 'carreau', 'trefle'],
        };
    }

    public function jokers(): int
    {
        return self::Pro === $this ? 2 : 1;
    }

    /** Carte retournée dans une pile. */
    public function revealPoints(): int
    {
        return self::Pro === $this ? 200 : 20;
    }

    /** Famille complète (du Roi à l'As, d'une même couleur) rangée sur la pile de base. */
    public function familyPoints(): int
    {
        return self::Pro === $this ? 5000 : 100;
    }

    /** Pile de cartes vidée (une fois par pile). */
    public function emptyColumnPoints(): int
    {
        return self::Pro === $this ? 1000 : 100;
    }

    /** Toutes les cartes rangées. */
    public function finishPoints(): int
    {
        return self::Pro === $this ? 20000 : 250;
    }

    /** Partie finie sans utiliser la pile du Joker (pas de bonus au Pro). */
    public function noJokerPoints(): int
    {
        return self::Pro === $this ? 0 : 100;
    }

    /** Bonus temps, par seconde restante, si toutes les cartes sont rangées. */
    public function pointsPerSecond(): int
    {
        return self::Pro === $this ? 50 : 5;
    }

    /** Coût d'une annulation (impossible si le score ne suffit pas). */
    public function undoCost(): int
    {
        return self::Pro === $this ? 500 : 50;
    }

    /**
     * Pro : chaque pile talon se laisse regarder en entier avant de choisir ; sinon seule sa première carte est visible.
     */
    public function showsWholeStock(): bool
    {
        return self::Pro === $this;
    }
}
