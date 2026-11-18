<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Dos des cartes, choisis par l'admin pour chaque jeu, indépendamment des faces (CardSet).
 * Chaque dos est une image de public/images/cartes/dos/.
 */
enum CardBack: string
{
    case Modern = 'moderne';

    public function label(): string
    {
        return match ($this) {
            self::Modern => 'Moderne',
        };
    }

    /** Image du dos, à passer à asset(). */
    public function path(): string
    {
        return 'images/cartes/dos/'.$this->value.'.svg';
    }
}
