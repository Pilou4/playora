<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Game;
use App\Form\Admin\GameSettingFormType;
use App\Repository\GameSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages d'un jeu : tapis, cartes, durée d'un tour. Ils valent pour toutes les nouvelles parties.
 * Et le jeu actif ou inactif (inactif : caché de la liste des jeux, seuls les admins y jouent).
 */
#[IsGranted('ROLE_ADMIN')]
final class GameSettingAdminController extends AbstractController
{
    private const string SAVED = 'Réglages enregistrés : ils s’appliquent aux nouvelles parties.';
    private const string ACTIVE_TOKEN = 'game-active-';

    #[Route('/admin/jeux/{game}/reglages', name: 'admin_game_settings', requirements: ['game' => new EnumRequirement(Game::class)], methods: ['GET', 'POST'])]
    public function edit(Game $game, Request $request, GameSettingRepository $settings, EntityManagerInterface $entityManager): Response
    {
        $setting = $settings->forGame($game);
        $form = $this->createForm(GameSettingFormType::class, [
            'carpet' => $setting->getCarpet(),
            'cardSet' => $setting->getCardSet(),
            'cardBack' => $setting->getCardBack(),
            'turnSeconds' => $setting->getTurnSeconds(),
            'bidSeconds' => $setting->getBidSeconds(),
        ], ['game' => $game]);
        $form->handleRequest($request);

        // Envoi par le JS de la page : réponse JSON, la page ne se recharge pas (message affiché en haut)
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{carpet: \App\Enum\Carpet, cardSet: \App\Enum\CardSet, cardBack: \App\Enum\CardBack, turnSeconds: int, bidSeconds: int} $data */
            $data = $form->getData();
            $setting->update($data['carpet'], $data['cardSet'], $data['turnSeconds'], $data['bidSeconds'], $data['cardBack']);
            $entityManager->persist($setting);
            $entityManager->flush();

            if ($json) {
                return new JsonResponse(['message' => self::SAVED]);
            }

            $this->addFlash('success', self::SAVED);

            return $this->redirectToRoute('admin_game_settings', ['game' => $game->value], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $json) {
            $errors = $form->getErrors(true);

            return new JsonResponse([
                'message' => 'Réglages non enregistrés : '.(\count($errors) > 0 ? $errors[0]->getMessage() : 'vérifiez les choix.'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('admin/jeu/reglages.html.twig', [
            'game' => $game,
            'form' => $form,
            'active' => $setting->isActive(),
            'active_token' => self::ACTIVE_TOKEN.$game->value,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Jeu actif ou inactif. Envoyé par le JS de la page dès que l'interrupteur change (réponse JSON),
     * ou par le formulaire seul (retour sur la page des réglages, avec un message).
     */
    #[Route('/admin/jeux/{game}/actif', name: 'admin_game_active', requirements: ['game' => new EnumRequirement(Game::class)], methods: ['POST'])]
    public function active(Game $game, Request $request, GameSettingRepository $settings, EntityManagerInterface $entityManager): Response
    {
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        if (!$this->isCsrfTokenValid(self::ACTIVE_TOKEN.$game->value, (string) $request->request->get('_token'))) {
            $message = 'Le formulaire a expiré : rechargez la page et réessayez.';

            if ($json) {
                return new JsonResponse(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('error', $message);

            return $this->redirectToRoute('admin_game_settings', ['game' => $game->value], Response::HTTP_SEE_OTHER);
        }

        $setting = $settings->forGame($game);
        $setting->setActive($request->request->getBoolean('active'));
        $entityManager->persist($setting);
        $entityManager->flush();

        $message = $setting->isActive()
            ? $game->label().' est en ligne : il apparaît dans la liste des jeux.'
            : $game->label().' est hors ligne : il est caché de la liste des jeux, seuls les admins peuvent y jouer.';

        if ($json) {
            return new JsonResponse(['active' => $setting->isActive(), 'message' => $message]);
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('admin_game_settings', ['game' => $game->value], Response::HTTP_SEE_OTHER);
    }
}
