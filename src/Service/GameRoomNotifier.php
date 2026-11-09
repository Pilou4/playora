<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GameRoom;
use App\Enum\Game;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Prévient les navigateurs qui regardent la liste des salons qu'il y a du nouveau.
 * Le message ne contient aucune donnée personnelle : chaque navigateur recharge
 * ensuite sa propre liste, avec ses propres droits.
 */
final readonly class GameRoomNotifier
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(Game $game): string
    {
        return 'playora/jeux/'.$game->value.'/salons';
    }

    public function roomChanged(GameRoom $room): void
    {
        try {
            $this->hub->publish(new Update(self::topic($room->getGame()), json_encode(['room' => $room->getId()], \JSON_THROW_ON_ERROR)));
        } catch (RuntimeException $exception) {
            // Le jeu continue sans le temps réel : la liste se mettra à jour au prochain chargement
            $this->logger->warning('Mercure injoignable : {message}', ['message' => $exception->getMessage()]);
        }
    }
}
