<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GameRoom;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, GameRoom>
 */
final class GameRoomVoter extends Voter
{
    /** Voir la partie : seulement les joueurs assis à cette table. */
    public const string PLAY = 'GAME_ROOM_PLAY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::PLAY === $attribute && $subject instanceof GameRoom;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && null !== $subject->getSeatOf($user);
    }
}
