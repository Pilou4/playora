<?php

declare(strict_types=1);

namespace App\Game\Belote;

enum Phase: string
{
    /** 1er tour d'enchères : prendre la carte retournée ou passer. */
    case FirstBidding = 'encheres-1';
    /** 2e tour : choisir une autre couleur ou passer. */
    case SecondBidding = 'encheres-2';
    /** Coinche et contrée : enchères de 80 à 160 (ou capot), coinche, surcoinche. */
    case Auction = 'annonces';
    case Playing = 'jeu';
    case Finished = 'terminee';

    public function isBidding(): bool
    {
        return self::FirstBidding === $this || self::SecondBidding === $this || self::Auction === $this;
    }
}
