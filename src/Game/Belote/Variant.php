<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Variantes de la belote, choisies à la création du salon.
 *
 * - Classique : carte retournée, deux tours (prendre ou passer).
 * - Coinche et contrée (règles GameDuell, comptées de la même façon) : enchères de 80 à 160, capot ou générale,
 *   coinche / contre (×2) et surcoinche / surcontre (×4) ; le preneur marque ses points + le contrat.
 *
 * Les annonces (tierce, cinquante, cent, carrés) se choisissent à la création du salon, pour toutes les variantes.
 */
enum Variant: string
{
    case Classic = 'classique';
    case Coinche = 'coinche';
    case Contree = 'contree';

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Belote',
            self::Coinche => 'Coinche',
            self::Contree => 'Contrée',
        };
    }

    /**
     * Points à atteindre proposés à la création du salon (GameDuell : 501 à la belote, 701 à la coinche et à la contrée).
     *
     * @return list<int>
     */
    public function targetPointsChoices(): array
    {
        return match ($this) {
            self::Classic => [501, 1001, 1501],
            self::Coinche, self::Contree => [701, 1001, 1501],
        };
    }

    public function defaultTargetPoints(): int
    {
        return $this->hasAuction() ? 701 : $this->targetPointsChoices()[1];
    }

    /** Variantes à enchères (sans carte retournée). */
    public function hasAuction(): bool
    {
        return self::Classic !== $this;
    }
}
