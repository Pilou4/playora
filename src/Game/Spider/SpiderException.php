<?php

declare(strict_types=1);

namespace App\Game\Spider;

use App\Game\Patience\PatienceException;

/**
 * Coup refusé par les règles du Spider : le message est en français, pour le joueur.
 */
final class SpiderException extends PatienceException
{
}
