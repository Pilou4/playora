<?php

declare(strict_types=1);

namespace App\Game\CardPatience;

use App\Game\Patience\PatienceException;

/**
 * Coup refusé au FreeCell, à la Pyramide ou au Golf solitaire (le message, en français, est montré au joueur).
 */
final class CardPatienceException extends PatienceException
{
}
