<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Service\UserRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

#[Route('/inscription')]
final class RegistrationController extends AbstractController
{
    private const string SESSION_EMAIL = 'registration_email';
    private const string SESSION_PSEUDO = 'verified_pseudo';
    /** Compte créé dans CE navigateur : sert à la page d'attente et à la connexion automatique. */
    private const string SESSION_PENDING_USER = 'pending_user_id';
    /** Lien ouvert dans un navigateur connecté avec un autre compte : le pseudo de ce compte-là. */
    private const string SESSION_OTHER_ACCOUNT = 'confirmed_other_account';
    private const string TOO_MANY_ATTEMPTS = 'Trop de tentatives : réessayez un peu plus tard.';
    private const string EMAIL_TAKEN = 'Un compte existe déjà avec cette adresse e-mail.';

    #[Route('', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        UserRegistration $registration,
        RateLimiterFactoryInterface $registrationLimiter,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, pseudo: string, plainPassword: string} $data */
            $data = $form->getData();

            if (!$registrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError(self::TOO_MANY_ATTEMPTS));
            } elseif ($this->isTaken($form, $users, $data)) {
                // Adresse ou pseudo déjà pris : le message est sous le champ, rien n'est enregistré ni envoyé
            } else {
                $user = $registration->register($data['email'], $data['pseudo'], $data['plainPassword']);
                $session = $request->getSession();
                $session->set(self::SESSION_EMAIL, $data['email']);
                $session->set(self::SESSION_PENDING_USER, $user?->getId());

                return $this->redirectToRoute('app_register_check_email');
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }

    #[Route('/verifiez-vos-emails', name: 'app_register_check_email', methods: ['GET'])]
    public function checkEmail(Request $request, UserRepository $users, Security $security): Response
    {
        if ($this->isPendingUserConfirmed($request, $users)) {
            // Lien cliqué ailleurs (autre navigateur, messagerie) : le navigateur qui a créé le compte s'y connecte
            $pending = $users->find((int) $request->getSession()->get(self::SESSION_PENDING_USER));

            if (null === $this->getUser() && null !== $pending && !$pending->isDeleted()) {
                $security->login($pending, 'form_login', 'main');
            }

            return $this->redirectToRoute('app_register_confirmed');
        }

        return $this->render('registration/check_email.html.twig', [
            'email' => $request->getSession()->get(self::SESSION_EMAIL),
        ]);
    }

    /**
     * Interrogée par la page d'attente : ne renseigne que sur le compte créé dans cette session.
     */
    #[Route('/statut', name: 'app_register_status', methods: ['GET'])]
    public function status(Request $request, UserRepository $users): JsonResponse
    {
        $response = new JsonResponse(['confirmed' => $this->isPendingUserConfirmed($request, $users)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/verification', name: EmailVerifier::ROUTE, methods: ['GET'])]
    public function verifyEmail(
        Request $request,
        UserRepository $users,
        EmailVerifier $emailVerifier,
        EntityManagerInterface $entityManager,
        Security $security,
    ): Response {
        $user = $users->find($request->query->getInt('id'));

        if (null === $user || $user->isDeleted()) {
            return $this->redirectToRoute('app_register_link_expired');
        }

        if (!$user->isEmailVerified()) {
            try {
                $emailVerifier->confirm($request, $user);
            } catch (VerifyEmailExceptionInterface) {
                return $this->redirectToRoute('app_register_link_expired');
            }

            $entityManager->flush();
        }

        $session = $request->getSession();
        $current = $this->getUser();

        // Connexion automatique seulement dans le navigateur qui a créé le compte
        if ($session->get(self::SESSION_PENDING_USER) === $user->getId() && null === $current) {
            $security->login($user, 'form_login', 'main');
        } elseif ($current instanceof User && $current->getId() !== $user->getId()) {
            // Ce navigateur est connecté avec un autre compte : on le dit, on ne fait pas comme si c'était le nouveau
            $session->set(self::SESSION_OTHER_ACCOUNT, $current->getPseudo());
        }

        $request->getSession()->set(self::SESSION_PSEUDO, $user->getPseudo());

        return $this->redirectToRoute('app_register_confirmed');
    }

    #[Route('/confirmee', name: 'app_register_confirmed', methods: ['GET'])]
    public function confirmed(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove(self::SESSION_PENDING_USER);
        $other = $session->remove(self::SESSION_OTHER_ACCOUNT);

        // Connecté avec le nouveau compte : on passe tout de suite au choix de l'avatar
        if ($this->getUser() instanceof User && null === $other) {
            return $this->redirectToRoute('app_welcome');
        }

        return $this->render('registration/confirmed.html.twig', [
            'pseudo' => $session->remove(self::SESSION_PSEUDO),
            'other' => $other,
        ]);
    }

    #[Route('/lien-expire', name: 'app_register_link_expired', methods: ['GET'])]
    public function linkExpired(Request $request): Response
    {
        return $this->render('registration/link_expired.html.twig', [
            'email' => $request->getSession()->get(self::SESSION_EMAIL),
        ]);
    }

    #[Route('/renvoyer-le-lien', name: 'app_register_resend', methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function resend(
        Request $request,
        UserRegistration $registration,
        ValidatorInterface $validator,
        RateLimiterFactoryInterface $verificationEmailLimiter,
    ): Response {
        $session = $request->getSession();
        $email = trim($request->getPayload()->getString('email')) ?: (string) $session->get(self::SESSION_EMAIL);

        if (\count($validator->validate($email, [new NotBlank(), new Email()])) > 0) {
            $this->addFlash('resend_error', 'Indiquez une adresse e-mail valide.');

            return $this->redirectToRoute('app_register_link_expired');
        }

        if (!$verificationEmailLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('resend_error', self::TOO_MANY_ATTEMPTS);

            return $this->redirectToRoute('app_register_link_expired');
        }

        $registration->resendConfirmation($email);
        $session->set(self::SESSION_EMAIL, $email);

        return $this->redirectToRoute('app_register_check_email');
    }

    /**
     * Une adresse e-mail et un pseudo ne servent qu'à un seul compte (sans tenir compte des majuscules ;
     * ni des accents pour le pseudo). Chaque champ déjà pris reçoit son message.
     *
     * @param FormInterface<mixed>                                          $form
     * @param array{email: string, pseudo: string, plainPassword: string} $data
     */
    private function isTaken(FormInterface $form, UserRepository $users, array $data): bool
    {
        $taken = false;

        if ($users->emailExists($data['email'])) {
            $form->get('email')->addError(new FormError(self::EMAIL_TAKEN));
            $taken = true;
        }

        if ($users->pseudoExists($data['pseudo'])) {
            $form->get('pseudo')->addError(new FormError('Ce pseudo est déjà pris.'));
            $taken = true;
        }

        return $taken;
    }

    private function isPendingUserConfirmed(Request $request, UserRepository $users): bool
    {
        if (null !== $this->getUser()) {
            return true;
        }

        $id = $request->getSession()->get(self::SESSION_PENDING_USER);

        return \is_int($id) && true === $users->find($id)?->isEmailVerified();
    }
}
