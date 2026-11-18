<?php

declare(strict_types=1);

namespace App\Game\Patience;

/**
 * Coup refusé par les règles d'un jeu de patience (solitaire, Spider) : le message est en français, pour le joueur.
 */
class PatienceException extends \DomainException
{
}
