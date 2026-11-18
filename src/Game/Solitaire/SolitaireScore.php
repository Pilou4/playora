<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

/**
 * Barème (comme sur GameDuell).
 */
final class SolitaireScore
{
    /** Une carte retournée dans les piles de tri (21 au total). */
    public const int REVEAL = 100;
    /** Une carte rangée sur sa famille (52 au total). */
    public const int FOUNDATION = 1000;
    /** Toutes les cartes rangées. */
    public const int COMPLETE = 10000;
    /** Chaque nouvelle consultation du talon à partir de la 4e. */
    public const int STOCK_PASS = -400;
    public const int FREE_PASSES = 3;
    /** Bouton « retour ». */
    public const int UNDO = -1000;
    /** Bonus temps : par seconde restante si tout est rangé. */
    public const int TIME_BONUS_PER_SECOND = 150;
    /** Bonus temps si l'on arrête (ou quitte) avant : 1 point par seconde restante, pour départager les scores égaux. */
    public const int STOP_BONUS_PER_SECOND = 1;
    /** Temps d'une partie. */
    public const int SECONDS = 300;
}
