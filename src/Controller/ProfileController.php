<?php

declare(strict_types=1);

namespace App\Controller;

use App\Avatar\AvatarCatalog;
use App\Entity\Profile;
use App\Entity\User;
use App\Form\PseudoFormType;
use App\Repository\ProfileRepository;
use App\Repository\UserRepository;
use App\Service\CurrentPlayer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    private const string PSEUDO_TAKEN = 'Ce pseudo est déjà pris.';

    #[Route('/mon-profil', name: 'app_profile', methods: ['GET', 'POST'])]
    public function show(Request $request, UserRepository $users, EntityManagerInterface $entityManager, CurrentPlayer $player): Response
    {
        $user = $this->player();
        $form = $this->createForm(PseudoFormType::class, ['pseudo' => $user->getPseudo()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $pseudo = trim((string) $form->get('pseudo')->getData());
            $owner = $users->findOneBy(['pseudo' => $pseudo]);

            if (null !== $owner && $owner !== $user) {
                $form->get('pseudo')->addError(new FormError(self::PSEUDO_TAKEN));
            } else {
                $previous = $user->getPseudo();

                try {
                    $user->changePseudo($pseudo);
                    $entityManager->flush();
                    $this->addFlash('profile_notice', 'Votre pseudo est enregistré.');

                    return $this->redirectToRoute('app_profile');
                } catch (UniqueConstraintViolationException) {
                    // Pris au même instant par un autre joueur
                    $user->changePseudo($previous);
                    $form->get('pseudo')->addError(new FormError(self::PSEUDO_TAKEN));
                }
            }
        }

        return $this->render('profile/show.html.twig', [
            'form' => $form,
            'avatar_code' => $player->avatarCode(),
        ]);
    }

    /**
     * Juste après l'inscription (et pour tout compte sans avatar) : choisir son avatar.
     */
    #[Route('/bienvenue', name: 'app_welcome', methods: ['GET', 'POST'])]
    public function welcome(
        Request $request,
        CurrentPlayer $player,
        AvatarCatalog $catalog,
        ProfileRepository $profiles,
        EntityManagerInterface $entityManager,
    ): Response {
        if (null !== $player->avatarCode()) {
            return $this->redirectToRoute('app_home');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $code = (string) $request->request->get('avatar');

            if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
                $error = 'Le formulaire a expiré : merci de réessayer.';
            } elseif (!\in_array($code, $catalog->gallery(), true)) {
                $error = 'Choisissez un avatar pour continuer.';
            } else {
                $user = $this->player();
                $profile = $profiles->findOneByUser($user) ?? new Profile($user);
                $profile->setAvatar($code);
                $entityManager->persist($profile);
                $entityManager->flush();

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('profile/welcome.html.twig', [
            'gallery' => $catalog->gallery(),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function player(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
