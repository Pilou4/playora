<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ForgotPasswordFormType;
use App\Form\ResetPasswordFormType;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Security\PasswordResetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Mot de passe oublié. Même réponse que le compte existe ou non ; lien à usage unique,
 * valable 30 minutes, retiré de l'adresse dès l'ouverture (ni historique, ni Referer).
 */
#[Route('/mot-de-passe-oublie')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    private const string SESSION_EMAIL = 'forgot_password_email';
    private const string LINK_INVALID = 'Ce lien a expiré ou a déjà servi : demandez-en un nouveau.';

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly PasswordResetMailer $mailer,
    ) {
    }

    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, UserRepository $users, RateLimiterFactoryInterface $forgotPasswordLimiter): Response
    {
        $form = $this->createForm(ForgotPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$forgotPasswordLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de demandes : réessayez un peu plus tard.'));
            } else {
                $email = (string) $form->get('email')->getData();
                $this->sendLink($users->findOneBy(['email' => User::normalizeEmail($email)]));
                $request->getSession()->set(self::SESSION_EMAIL, $email);

                return $this->redirectToRoute('app_forgot_password_check_email');
            }
        }

        return $this->noStore($this->render('reset_password/request.html.twig', ['form' => $form]));
    }

    #[Route('/verifiez-vos-emails', name: 'app_forgot_password_check_email', methods: ['GET'])]
    public function checkEmail(Request $request): Response
    {
        return $this->noStore($this->render('reset_password/check_email.html.twig', [
            'email' => $request->getSession()->get(self::SESSION_EMAIL),
        ]));
    }

    #[Route('/nouveau/{token}', name: 'app_reset_password', requirements: ['token' => '[A-Za-z0-9]{1,100}'], defaults: ['token' => null], methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        ResetPasswordRequestRepository $resetRequests,
        EntityManagerInterface $entityManager,
        ?string $token = null,
    ): Response {
        // Le jeton passe dans la session, puis on recharge la page sans lui dans l'adresse
        if (null !== $token) {
            $this->storeTokenInSession($token);

            return $this->noStore($this->redirectToRoute('app_reset_password'));
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            return $this->linkInvalid();
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            return $this->linkInvalid();
        }

        if ($user->isDeleted()) {
            return $this->linkInvalid();
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

            // Le lien est arrivé dans sa boîte : l'adresse est prouvée
            if (!$user->isEmailVerified()) {
                $user->markEmailAsVerified();
            }

            $resetRequests->removeAllFor($user);
            $entityManager->flush();
            $this->cleanSessionAfterReset();

            $this->mailer->sendPasswordChanged($user, $this->generateUrl('app_forgot_password_request', [], UrlGeneratorInterface::ABSOLUTE_URL));
            $this->addFlash('login_notice', 'Votre mot de passe a été modifié : connectez-vous avec le nouveau.');

            return $this->redirectToRoute('app_login');
        }

        return $this->noStore($this->render('reset_password/reset.html.twig', ['form' => $form]));
    }

    /**
     * Rien n'indique au visiteur si l'adresse a un compte : sans compte, aucun e-mail ne part.
     */
    private function sendLink(?User $user): void
    {
        if (null === $user || $user->isDeleted()) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Une demande récente existe déjà (1 par heure) : on ne renvoie rien
            return;
        }

        $this->mailer->sendResetLink($user, $this->generateUrl('app_reset_password', ['token' => $resetToken->getToken()], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    private function linkInvalid(): Response
    {
        $this->cleanSessionAfterReset();
        $this->addFlash('reset_error', self::LINK_INVALID);

        return $this->redirectToRoute('app_forgot_password_request');
    }

    /**
     * Pages sensibles : jamais gardées en cache, et l'adresse n'est jamais transmise à un autre site.
     */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
