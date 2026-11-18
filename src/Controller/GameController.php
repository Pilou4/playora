<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GameRoom;
use App\Entity\PatienceGame;
use App\Entity\User;
use App\Enum\Game;
use App\Enum\RoomStatus;
use App\Repository\BeloteGameRepository;
use App\Repository\GameSettingRepository;
use App\Repository\GolfGameRepository;
use App\Repository\HuitGameRepository;
use App\Repository\PatienceGameRepository;
use App\Repository\PresidentGameRepository;
use App\Repository\RamiGameRepository;
use App\Repository\TarotGameRepository;
use App\Security\Voter\GameRoomVoter;
use App\Service\BeloteNotifier;
use App\Service\BeloteTable;
use App\Service\BeloteView;
use App\Service\GameRoomManager;
use App\Service\GameRoomNotifier;
use App\Service\GameRoomState;
use App\Service\GolfTable;
use App\Service\GolfView;
use App\Service\HuitTable;
use App\Service\HuitView;
use App\Service\PatienceTable;
use App\Service\PlayerRoom;
use App\Service\PresidentTable;
use App\Service\PresidentView;
use App\Service\RamiTable;
use App\Service\RamiView;
use App\Service\CardPatienceView;
use App\Service\SolitaireView;
use App\Service\SpiderView;
use App\Service\TarotTable;
use App\Service\TarotView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Un seul contrôleur de pages pour tous les jeux. La page des salons ne fait qu'afficher :
 * toutes les actions passent par l'API (Api\GameRoomApiController), sans rechargement.
 */
#[Route('/jeux/{game}', requirements: ['game' => new EnumRequirement(Game::class)])]
final class GameController extends AbstractController
{
    #[Route('', name: 'app_game_lobby', methods: ['GET'])]
    public function lobby(Game $game, GameRoomManager $manager, GameRoomState $state, GameSettingRepository $settings): Response
    {
        $manager->releaseAbsentPlayers($game);
        $user = $this->getUser();

        return $this->render('game/lobby.html.twig', [
            'game' => $game,
            // L'état de départ est dans la page : la liste s'affiche sans attendre ni bouger
            'state' => $state->forGame($game, $user instanceof User ? $user : null),
            'topic' => GameRoomNotifier::topic($game),
            // Durées réglées dans l'admin, annoncées dans la fenêtre de création
            'settings' => $settings->forGame($game),
        ]);
    }

    /**
     * Duel solo : page « Préparez-vous » avant de créer une partie (le nombre de joueurs vient de la fenêtre de création).
     * « Jouer maintenant » crée le salon par l'API, et la partie commence aussitôt.
     */
    #[Route('/participer', name: 'app_game_prepare', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function prepare(Game $game, Request $request, PlayerRoom $playerRoom): Response
    {
        $players = $request->query->getInt('joueurs', $game->playerCount());

        if (!$game->isPatience() || !\in_array($players, $game->playerCountChoices(), true)) {
            throw $this->createNotFoundException();
        }

        return $this->preparePage($game, null, $players, $playerRoom);
    }

    /**
     * Duel solo : page « Préparez-vous » avant de rejoindre un salon (la même donne que les joueurs déjà passés).
     */
    #[Route('/salons/{id}/participer', name: 'app_game_prepare_join', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function prepareJoin(Game $game, #[MapEntity(id: 'id')] GameRoom $room, PlayerRoom $playerRoom): Response
    {
        if (!$game->isPatience() || $room->getGame() !== $game) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();

        // Déjà à cette table : sa partie (ou son résultat)
        if ($user instanceof User && null !== $room->getSeatOf($user)) {
            return $this->redirectToRoute('app_game_play', ['game' => $game->value, 'id' => $room->getId()]);
        }

        return $this->preparePage($game, $room, $room->getPlayerCount(), $playerRoom);
    }

    #[Route('/parties/{id}', name: 'app_game_play', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(GameRoomVoter::PLAY, 'room')]
    public function play(
        Game $game,
        #[MapEntity(id: 'id')] GameRoom $room,
        BeloteGameRepository $games,
        BeloteTable $table,
        BeloteView $view,
        TarotGameRepository $tarotGames,
        TarotTable $tarotTable,
        TarotView $tarotView,
        PatienceGameRepository $patienceGames,
        PatienceTable $patienceTable,
        SolitaireView $solitaireView,
        SpiderView $spiderView,
        CardPatienceView $cardPatienceView,
        RamiGameRepository $ramiGames,
        RamiTable $ramiTable,
        RamiView $ramiView,
        HuitGameRepository $huitGames,
        HuitTable $huitTable,
        HuitView $huitView,
        GolfGameRepository $golfGames,
        GolfTable $golfTable,
        GolfView $golfView,
        PresidentGameRepository $presidentGames,
        PresidentTable $presidentTable,
        PresidentView $presidentView,
    ): Response {
        if ($room->getGame() !== $game) {
            throw $this->createNotFoundException();
        }

        if ($game->isTarot()) {
            return $this->playTarot($room, $tarotGames, $tarotTable, $tarotView);
        }

        if ($game->isHuit()) {
            return $this->playHuit($room, $huitGames, $huitTable, $huitView);
        }

        if ($game->isGolf()) {
            return $this->playGolf($room, $golfGames, $golfTable, $golfView);
        }

        if ($game->isPresident()) {
            return $this->playPresident($room, $presidentGames, $presidentTable, $presidentView);
        }

        if ($game->isRami()) {
            return $this->playRami($room, $ramiGames, $ramiTable, $ramiView);
        }

        if ($game->isPatience()) {
            $view = match (true) {
                $game->isSpider() => $spiderView->forPlayer(...),
                $game->isCardPatience() => $cardPatienceView->forPlayer(...),
                default => $solitaireView->forPlayer(...),
            };

            return $this->playPatience($room, $patienceGames, $patienceTable, $view);
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

    private function playTarot(GameRoom $room, TarotGameRepository $games, TarotTable $table, TarotView $view): Response
    {
        $tarot = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $tarot || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $tarot) ?? throw $this->createAccessDeniedException();

        return $this->render('game/tarot/table.html.twig', [
            'room' => $room,
            'game' => $tarot,
            'view' => $view->forPlayer($tarot, $position),
            'topic' => BeloteNotifier::tarotTopic((int) $tarot->getId()),
        ]);
    }

    private function playHuit(GameRoom $room, HuitGameRepository $games, HuitTable $table, HuitView $view): Response
    {
        $huit = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $huit || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $huit) ?? throw $this->createAccessDeniedException();

        return $this->render('game/huit/table.html.twig', [
            'room' => $room,
            'game' => $huit,
            'view' => $view->forPlayer($huit, $position),
            'topic' => BeloteNotifier::huitTopic((int) $huit->getId()),
        ]);
    }

    private function playPresident(GameRoom $room, PresidentGameRepository $games, PresidentTable $table, PresidentView $view): Response
    {
        $president = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $president || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $president) ?? throw $this->createAccessDeniedException();

        return $this->render('game/president/table.html.twig', [
            'room' => $room,
            'game' => $president,
            'view' => $view->forPlayer($president, $position),
            'topic' => BeloteNotifier::presidentTopic((int) $president->getId()),
        ]);
    }

    private function playGolf(GameRoom $room, GolfGameRepository $games, GolfTable $table, GolfView $view): Response
    {
        $golf = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $golf || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $golf) ?? throw $this->createAccessDeniedException();

        return $this->render('game/golf/table.html.twig', [
            'room' => $room,
            'game' => $golf,
            'view' => $view->forPlayer($golf, $position),
            'topic' => BeloteNotifier::golfTopic((int) $golf->getId()),
        ]);
    }

    private function playRami(GameRoom $room, RamiGameRepository $games, RamiTable $table, RamiView $view): Response
    {
        $rami = $games->findOneByRoom($room);
        $user = $this->getUser();

        if (RoomStatus::Playing !== $room->getStatus() || null === $rami || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $rami) ?? throw $this->createAccessDeniedException();

        return $this->render('game/rami/table.html.twig', [
            'room' => $room,
            'game' => $rami,
            'view' => $view->forPlayer($rami, $position),
            'topic' => BeloteNotifier::ramiTopic((int) $rami->getId()),
        ]);
    }

    private function preparePage(Game $game, ?GameRoom $room, int $players, PlayerRoom $playerRoom): Response
    {
        $user = $this->getUser();
        $current = $user instanceof User ? $playerRoom->current($user) : null;

        // Une partie déjà en cours (un seul salon à la fois) : on y retourne
        if (null !== $current) {
            return $this->redirectToRoute('app_game_play', ['game' => $current->getGame()->value, 'id' => $current->getId()]);
        }

        $seat = $room?->getFirstFreeSeat();

        return $this->render('game/prepare.html.twig', [
            'game' => $game,
            'room' => $room,
            'players' => $players,
            // Rejoindre : la première place libre (null : salon complet ou fermé)
            'position' => null !== $room && $room->isWaiting() ? $seat?->getPosition() : null,
        ]);
    }

    /**
     * Solitaire, Spider, ou FreeCell / Pyramide / Golf solitaire (une table commune) : chaque jeu a sa table.
     *
     * @param \Closure(PatienceGame, int): array<string, mixed> $view la vue du joueur (SolitaireView, SpiderView)
     */
    private function playPatience(GameRoom $room, PatienceGameRepository $games, PatienceTable $table, \Closure $view): Response
    {
        $patience = $games->findOneByRoom($room);
        $user = $this->getUser();

        // Duel solo : la table se voit dès que le joueur a pris sa place (salon encore ouvert ou partie finie)
        if (null === $patience || !$user instanceof User) {
            return $this->redirectToRoute('app_game_lobby', ['game' => $room->getGame()->value]);
        }

        $position = $table->positionOf($user, $patience) ?? throw $this->createAccessDeniedException();

        $template = match (true) {
            $room->getGame()->isSpider() => 'game/spider/table.html.twig',
            $room->getGame()->isCardPatience() => 'game/patience/table.html.twig',
            default => 'game/solitaire/table.html.twig',
        };

        return $this->render($template, [
            'room' => $room,
            'game' => $patience,
            'view' => $view($patience, $position),
            'topic' => BeloteNotifier::patienceTopic($patience),
        ]);
    }
}
