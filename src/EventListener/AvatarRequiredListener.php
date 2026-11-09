<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\CurrentPlayer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tout joueur a un avatar : sans avatar, chaque page du site renvoie vers « Choisissez votre avatar ».
 * L'API n'est pas concernée (elle répond toujours en JSON).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final readonly class AvatarRequiredListener
{
    /** Pages utiles pour choisir son avatar (ou partir). */
    private const array ALLOWED_ROUTES = ['app_welcome', 'app_profile_avatar', 'app_avatar_image', 'app_logout'];

    public function __construct(
        private CurrentPlayer $player,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');

        if (!$event->isMainRequest()
            || !$request->isMethod('GET')
            || '' === $route
            || str_starts_with($route, '_')
            || str_starts_with($route, 'api_')
            || \in_array($route, self::ALLOWED_ROUTES, true)
            || null === $this->player->user()
            || null !== $this->player->avatarCode()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_welcome')));
    }
}
