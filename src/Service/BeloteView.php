<?php

declare(strict_types=1);

namespace App\Service;

use App\Avatar\AvatarCatalog;
use App\Entity\BeloteGame;
use App\Entity\GameRoomSeat;
use App\Game\Belote\Announcement;
use App\Game\Belote\BeloteEngine;
use App\Game\Belote\BeloteRules;
use App\Game\Belote\BeloteScorer;
use App\Game\Belote\BeloteState;
use App\Game\Belote\Card;
use App\Game\Belote\CoincheScorer;
use App\Game\Belote\Phase;
use App\Game\Belote\TrickCard;
use App\Game\Belote\Variant;
use App\Repository\ProfileRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce qu'un joueur voit de la table : sa propre main seulement, le nombre de cartes des autres,
 * le pli, le score. Les places sont celles du moteur (0 à 3) ; « me » dit laquelle est la sienne.
 */
final readonly class BeloteView
{
    /** Un nom par place, jusqu'à 6 joueurs (rami). */
    private const array BOT_NAMES = ['Robot Bip', 'Robot Bop', 'Robot Zap', 'Robot Pix', 'Robot Pop', 'Robot Tic'];

    public function __construct(
        private ProfileRepository $profiles,
        private AvatarCatalog $catalog,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(BeloteGame $game, int $me): array
    {
        $state = $game->getState();
        $room = $game->getRoom();
        $codes = static fn (array $cards): array => array_map(static fn (Card $card): string => $card->code(), $cards);
        $trick = static fn (array $trick): array => array_map(static fn (TrickCard $played): array => [$played->position, $played->card->code()], $trick);

        return [
            'id' => $game->getId(),
            'version' => $game->getVersion(),
            'me' => $me,
            'phase' => $state->phase->value,
            'deal' => $state->dealNumber,
            'dealer' => $state->dealer,
            'turn' => $state->turn,
            'turned' => $state->turnedCard?->code(),
            'trump' => $state->trump?->value,
            'mode' => $state->mode->value,
            'taker' => $state->taker,
            'trick' => $trick($state->trick),
            'lastTrick' => $trick($state->lastTrick),
            'hand' => $codes($state->hand($me)),
            'playable' => $codes($state->playableCards($me)),
            'counts' => array_map('count', $state->hands),
            'scores' => $state->scores,
            'dealScores' => $state->dealScores,
            'points' => $this->dealPoints($state),
            'target' => $state->targetPoints,
            'winner' => $state->winnerTeam,
            'variant' => $state->variant->value,
            'contract' => $state->contract,
            'multiplier' => $state->multiplier,
            'coincher' => $state->coincher,
            'auction' => $this->auction($state, $me),
            'announcements' => $state->announcements,
            'declarable' => array_map(static fn (Announcement $a): string => $a->name(), (new BeloteEngine())->declarable($state, $me)),
            'declared' => array_map(static fn (array $list): array => array_map(static fn (Announcement $a): string => $a->name(), $list), $state->declarations),
            'announceTeam' => $state->announceTeam,
            'announcePoints' => $state->announcePoints,
            'turnSeconds' => $room->getTurnSeconds(),
            'bidSeconds' => $room->getBidSeconds(),
            'secondsLeft' => round($game->secondsLeft(new \DateTimeImmutable()), 1),
            'players' => $this->players($room->getSeats()),
        ];
    }

    /**
     * Événements d'une action, sans les cartes des autres joueurs.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    public function events(array $events, int $me): array
    {
        return array_map(static function (array $event) use ($me): array {
            if (isset($event['cards'])) {
                $event['counts'] = array_map('count', $event['cards']);
                $event['cards'] = $event['cards'][$me] ?? [];
            }

            return $event;
        }, $events);
    }

    /**
     * Coinche et contrée : ce que je peux faire si c'est à moi d'annoncer (null sinon).
     *
     * modes : on peut annoncer en tout atout ou en sans atout (coinche seulement).
     *
     * @return array{contracts: list<int>, coinche: bool, surcoinche: bool, modes: bool}|null
     */
    private function auction(BeloteState $state, int $me): ?array
    {
        if (Phase::Auction !== $state->phase || $state->turn !== $me) {
            return null;
        }

        $ours = null !== $state->taker && BeloteRules::team($state->taker) === BeloteRules::team($me);
        $contracts = null === $state->coincher
            ? array_values(array_filter(CoincheScorer::contracts(), static fn (int $contract): bool => $contract > ($state->contract ?? 0)))
            : [];

        return [
            'contracts' => $contracts,
            'coinche' => null !== $state->contract && !$ours && null === $state->coincher,
            'surcoinche' => null !== $state->coincher && $ours && 2 === $state->multiplier,
            'modes' => Variant::Coinche === $state->variant,
        ];
    }

    /**
     * Points gagnés par chaque place pendant la donne en cours (dix de der compris),
     * affichés à côté de chaque joueur : un score provisoire, comme sur une vraie table.
     *
     * @return list<int>
     */
    private function dealPoints(BeloteState $state): array
    {
        $points = [0, 0, 0, 0];
        $tricks = $state->hasContract() ? array_chunk($state->history, 4) : [];

        foreach ($tricks as $index => $trick) {
            if (4 === \count($trick)) {
                $points[$state->trickWinner($trick)] += $state->trickPoints($trick) + (7 === $index ? BeloteScorer::LAST_TRICK : 0);
            }
        }

        return $points;
    }

    /**
     * @param list<GameRoomSeat> $seats
     *
     * @return list<array<string, mixed>>
     */
    public function players(array $seats): array
    {
        $users = array_values(array_filter(array_map(static fn (GameRoomSeat $seat) => $seat->getUser(), $seats)));
        $avatars = $this->profiles->findAvatarCodes($users);
        $robots = $this->catalog->robots();
        $players = [];

        foreach ($seats as $seat) {
            $position = $seat->getPosition() - 1;
            $user = $seat->getUser();
            $code = null === $user ? ($robots[$position] ?? null) : ($avatars[(int) $user->getId()] ?? null);

            $players[$position] = [
                'position' => $position,
                'pseudo' => $user?->getPseudo() ?? self::BOT_NAMES[$position] ?? 'Robot',
                'bot' => null === $user,
                'team' => BeloteRules::team($position),
                'avatar' => null === $code || !$this->catalog->isValid($code) ? null : $this->urls->generate('app_avatar_face', ['code' => $code, 'v' => $this->catalog->version()]),
            ];
        }

        ksort($players);

        return array_values($players);
    }
}
