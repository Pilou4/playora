<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Enum\Game;
use App\Service\GameAvailability;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Jeu inactif : ses salons sont fermés aux joueurs (page 404, ou erreur 404 en JSON sur l'API) ; les admins y entrent.
 * Seules les entrées sont fermées (salons, créer, rejoindre, robots) : un joueur déjà assis peut finir sa partie ou partir.
 */
final readonly class GameAvailabilitySubscriber implements EventSubscriberInterface
{
    private const array ENTRY_ROUTES = [
        'app_game_lobby',
        'app_game_prepare',
        'app_game_prepare_join',
        'api_game_rooms',
        'api_game_room_create',
        'api_game_room_join',
        'api_game_room_bot',
    ];

    public function __construct(
        private GameAvailability $availability,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le routeur (32) et la sécurité (8) : la route et le joueur connecté sont connus
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !\in_array($request->attributes->get('_route'), self::ENTRY_ROUTES, true)) {
            return;
        }

        $value = $request->attributes->get('game');
        $game = $value instanceof Game ? $value : Game::tryFrom((string) $value);

        if (null !== $game && !$this->availability->isOpen($game)) {
            throw new NotFoundHttpException('Ce jeu n’est pas disponible.');
        }
    }
}
