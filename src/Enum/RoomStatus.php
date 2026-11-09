<?php

declare(strict_types=1);

namespace App\Enum;

enum RoomStatus: string
{
    /** Des places sont libres. */
    case Waiting = 'waiting';
    /** Les 4 places sont prises : la partie a commencé. */
    case Playing = 'playing';
    /** Tout le monde est parti avant le début, ou la partie est finie. */
    case Closed = 'closed';
}
