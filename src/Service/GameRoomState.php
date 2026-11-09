<?php

declare(strict_types=1);

namespace App\Service;

use App\Avatar\AvatarCatalog;
use App\Entity\GameRoom;
use App\Entity\GameRoomSeat;
use App\Entity\User;
use App\Enum\Game;
use App\Repository\GameRoomRepository;
use App\Repository\ProfileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * État de la liste des salons envoyé au navigateur (et plus tard à l'application mobile) :
 * seulement ce qui s'affiche, rien de personnel (ni e-mail, ni identifiant de compte).
 */
final readonly class GameRoomState
{
    public const int AVATAR_COLORS = 7;

    public function __construct(
        private GameRoomRepository $rooms,
        private ProfileRepository $profiles,
        private AvatarCatalog $catalog,
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'bool:PLAYORA_BOTS')]
        private bool $botsEnabled,
    ) {
    }

    /**
     * @return array{rooms: list<array<string, mixed>>, mine: array<string, mixed>|null, bots: bool}
     */
    public function forGame(Game $game, ?User $viewer): array
    {
        $mine = null === $viewer ? null : $this->rooms->findCurrentFor($viewer);

        $rooms = array_values(array_filter(
            $this->rooms->findWaitingPublic($game),
            static fn (GameRoom $room): bool => $room !== $mine,
        ));
        $avatars = $this->avatars([...$rooms, ...(null === $mine ? [] : [$mine])]);

        return [
            'rooms' => array_map(fn (GameRoom $room): array => $this->room($room, $viewer, $avatars), $rooms),
            'mine' => null === $mine ? null : [
                ...$this->room($mine, $viewer, $avatars),
                'status' => $mine->getStatus()->value,
                'playUrl' => $this->urls->generate('app_game_play', ['game' => $game->value, 'id' => $mine->getId()]),
            ],
            // Mode test : bouton « Robot » sous les places libres de son salon
            'bots' => $this->botsEnabled,
        ];
    }

    /**
     * Avatars de tous les joueurs affichés, en une seule requête.
     *
     * @param list<GameRoom> $rooms
     *
     * @return array<int, string>
     */
    private function avatars(array $rooms): array
    {
        $users = [];
        foreach ($rooms as $room) {
            foreach ($room->getSeats() as $seat) {
                if (null !== $seat->getUser()) {
                    $users[] = $seat->getUser();
                }
            }
        }

        return $this->profiles->findAvatarCodes($users);
    }

    /**
     * @param array<int, string> $avatars
     *
     * @return array<string, mixed>
     */
    private function room(GameRoom $room, ?User $viewer, array $avatars): array
    {
        return [
            'id' => $room->getId(),
            'targetPoints' => $room->getTargetPoints(),
            'rounds' => $room->getRounds(),
            'announcements' => $room->hasAnnouncements(),
            'seats' => array_map(fn (GameRoomSeat $seat): ?array => match (true) {
                $seat->isBot() => ['pseudo' => 'Robot', 'color' => $seat->getPosition(), 'avatar' => $this->avatarUrl($this->botAvatar($seat)), 'me' => false, 'bot' => true],
                null === $seat->getUser() => null,
                default => [
                    'pseudo' => $seat->getUser()->getPseudo(),
                    'color' => (int) $seat->getUser()->getId() % self::AVATAR_COLORS,
                    'avatar' => $this->avatarUrl($avatars[(int) $seat->getUser()->getId()] ?? null),
                    'me' => $seat->getUser() === $viewer,
                    'bot' => false,
                ],
            }, $room->getSeats()),
        ];
    }

    /**
     * Chaque place a son robot (avatars « robots » du catalogue).
     */
    private function botAvatar(GameRoomSeat $seat): ?string
    {
        return $this->catalog->robots()[$seat->getPosition() - 1] ?? null;
    }

    /**
     * Pas d'avatar (ou un ancien code qui n'existe plus) : la page affiche l'initiale.
     */
    private function avatarUrl(?string $code): ?string
    {
        return null === $code || !$this->catalog->isValid($code) ? null : $this->urls->generate('app_avatar_face', ['code' => $code, 'v' => $this->catalog->version()]);
    }
}
