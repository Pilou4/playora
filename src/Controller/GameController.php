<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameRoom;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Repository\BeloteGameRepository;
use App\Security\Voter\GameRoomVoter;
use App\Service\BeloteNotifier;
use App\Service\BeloteTable;
use App\Service\BeloteView;
use App\Service\GameRoomManager;
use App\Service\GameRoomNotifier;
use App\Service\GameRoomState;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Un seul contrôleur de pages pour tous les jeux. La page des salons ne fait qu'afficher :
 * toutes les actions passent par l'API (Api\GameRoomController), sans rechargement.
 */
#[Route('/jeux/{game}', requirements: ['game' => new EnumRequirement(Game::class)])]
final class GameController extends AbstractController
{
    #[Route('', name: 'app_game_lobby', methods: ['GET'])]
    public function lobby(Game $game, GameRoomManager $manager, GameRoomState $state): Response
    {
        $manager->releaseAbsentPlayers($game);
        $user = $this->getUser();

        return $this->render('game/lobby.html.twig', [
            'game' => $game,
            // L'état de départ est dans la page : la liste s'affiche sans attendre ni bouger
            'state' => $state->forGame($game, $user instanceof User ? $user : null),
            'topic' => GameRoomNotifier::topic($game),
        ]);
    }

    #[Route('/parties/{id}', name: 'app_game_play', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(GameRoomVoter::PLAY, 'room')]
    public function play(
        Game $game,
        #[MapEntity(id: 'id')] GameRoom $room,
        BeloteGameRepository $games,
        BeloteTable $table,
        BeloteView $view,
    ): Response {
        if ($room->getGame() !== $game) {
            throw $this->createNotFoundException();
        }

        $belote = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $belote || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $game->value]);
        }

        $position = $table->positionOf($user, $belote) ?? throw $this->createAccessDeniedException();

        // L'état de départ est dans la page : la table s'affiche tout de suite, sans attendre l'API
        return $this->render('game/belote/table.html.twig', [
            'room' => $room,
            'game' => $belote,
            'view' => $view->forPlayer($belote, $position),
            'topic' => BeloteNotifier::topic((int) $belote->getId()),
        ]);
    }
}
