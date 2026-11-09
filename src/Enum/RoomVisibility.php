<?php

declare(strict_types=1);

namespace App\Enum;

enum RoomVisibility: string
{
    /** Visible dans la liste des salons. */
    case Public = 'public';
    /** Accessible seulement avec le code ou le lien d'invitation. */
    case Invitation = 'invitation';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Tout le monde',
            self::Invitation => 'Sur invitation',
        };
    }
}
