<?php

declare(strict_types=1);

namespace App\Game\President;

/**
 * Coup refusé par les règles du Président : le message est en français, pour le joueur.
 */
final class PresidentException extends \DomainException
{
}
