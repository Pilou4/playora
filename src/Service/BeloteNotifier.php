<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BeloteGame;
use App\Entity\GolfGame;
use App\Entity\HuitGame;
use App\Entity\PresidentGame;
use App\Entity\PatienceGame;
use App\Entity\RamiGame;
use App\Entity\TarotGame;
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

    /** Tarot : même principe, sur son propre sujet. */
    public static function tarotTopic(int $gameId): string
    {
        return \sprintf('playora/jeux/tarot/parties/%d', $gameId);
    }

    /** Jeux de patience (solitaire, Spider) : une grille, une manche ou la partie vient de se terminer. */
    public static function patienceTopic(PatienceGame $game): string
    {
        return \sprintf('playora/jeux/%s/parties/%d', $game->getRoom()->getGame()->value, (int) $game->getId());
    }

    /** Rami : même principe, sur son propre sujet. */
    public static function ramiTopic(int $gameId): string
    {
        return \sprintf('playora/jeux/rami/parties/%d', $gameId);
    }

    /** 8 américain : même principe, sur son propre sujet. */
    public static function huitTopic(int $gameId): string
    {
        return \sprintf('playora/jeux/huit-americain/parties/%d', $gameId);
    }

    /** Golf : même principe, sur son propre sujet. */
    public static function golfTopic(int $gameId): string
    {
        return \sprintf('playora/jeux/golf/parties/%d', $gameId);
    }

    /** Président : même principe, sur son propre sujet. */
    public static function presidentTopic(int $gameId): string
    {
        return \sprintf('playora/jeux/president/parties/%d', $gameId);
    }

    public function changed(BeloteGame|TarotGame|PatienceGame|RamiGame|HuitGame|GolfGame|PresidentGame $game): void
    {
        $topic = match (true) {
            $game instanceof PresidentGame => self::presidentTopic((int) $game->getId()),
            $game instanceof GolfGame => self::golfTopic((int) $game->getId()),
            $game instanceof HuitGame => self::huitTopic((int) $game->getId()),
            $game instanceof TarotGame => self::tarotTopic((int) $game->getId()),
            $game instanceof RamiGame => self::ramiTopic((int) $game->getId()),
            $game instanceof PatienceGame => self::patienceTopic($game),
            default => self::topic((int) $game->getId()),
        };

        try {
            $this->hub->publish(new Update($topic, json_encode(['version' => $game->getVersion()], \JSON_THROW_ON_ERROR)));
        } catch (RuntimeException $exception) {
            // Hub injoignable : les autres verront le coup au prochain rafraîchissement
            $this->logger->warning('Mercure indisponible pour la partie {id}.', ['id' => $game->getId(), 'exception' => $exception]);
        }
    }
}
