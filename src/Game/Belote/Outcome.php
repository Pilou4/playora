<?php

declare(strict_types=1);

namespace App\Game\Belote;

enum Outcome: string
{
    /** Le preneur fait plus que la défense. */
    case Made = 'reussi';
    /** Le preneur est « dedans » : la défense marque 162. */
    case Failed = 'chute';
    /** Égalité : la défense marque, les points du preneur attendent la donne suivante. */
    case Litige = 'litige';
}
