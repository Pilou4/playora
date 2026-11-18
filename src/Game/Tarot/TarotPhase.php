<?php

declare(strict_types=1);

namespace App\Game\Tarot;

enum TarotPhase: string
{
    /** Chacun parle une fois : passer ou monter (petite, garde, garde sans, garde contre). */
    case Bidding = 'encheres';
    /** À 5 joueurs : le preneur appelle un Roi ; celui qui l'a est son partenaire (sans le dire). */
    case Call = 'appel';
    /** Petite ou garde : le chien est montré, le preneur fait son écart. */
    case Dog = 'chien';
    case Playing = 'jeu';
    case Finished = 'terminee';
}
