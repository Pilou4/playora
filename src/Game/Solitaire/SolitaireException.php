<?php

declare(strict_types=1);

namespace App\Game\Solitaire;

use App\Game\Patience\PatienceException;

/**
 * Coup refusé par les règles : le message est en français, pour le joueur.
 */
final class SolitaireException extends PatienceException
{
}
