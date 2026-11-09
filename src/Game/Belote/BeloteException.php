<?php

declare(strict_types=1);

namespace App\Game\Belote;

/**
 * Coup refusé par les règles (pas son tour, carte non autorisée…). Le message est affichable.
 */
final class BeloteException extends \DomainException
{
}
