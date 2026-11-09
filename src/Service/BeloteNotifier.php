<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BeloteGame;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Prévient les autres joueurs qu'un coup a été joué. Le message ne contient que le numéro de version :
 * chacun récupère ensuite sa propre vue par l'API (jamais la main d'un autre).
 */
final readonly class BeloteNotifier
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(int $gameId): string
    {
        return \sprintf('playora/jeux/belote/parties/%d', $gameId);
    }

    public function changed(BeloteGame $game): void
    {
        try {
            $this->hub->publish(new Update(self::topic((int) $game->getId()), json_encode(['version' => $game->getVersion()], \JSON_THROW_ON_ERROR)));
        } catch (RuntimeException $exception) {
            // Hub injoignable : les autres verront le coup au prochain rafraîchissement
            $this->logger->warning('Mercure indisponible pour la partie {id}.', ['id' => $game->getId(), 'exception' => $exception]);
        }
    }
}
