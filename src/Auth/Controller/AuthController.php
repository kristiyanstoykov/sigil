<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\EventSubscriber\UnverifiedLoginSubscriber;
use App\Auth\Form\RegistrationForm;
use App\Auth\Service\EmailVerifier;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Symfony handles logout via the firewall - this line is never reached.');
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        EmailVerifier $emailVerifier,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationForm::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get(RegistrationForm::E_PASSWORD)->getData()));
            $user->setRoles(['ROLE_SIGNER']);

            $em->persist($user);
            $em->flush();

            // A mail-provider failure must not 500 an already-created account:
            // steer the user to the resend page, where they can retry once the
            // provider recovers (failure details are in the log).
            if (!$this->sendConfirmationEmail($emailVerifier, $user)) {
                $request->getSession()->set(UnverifiedLoginSubscriber::SESSION_EMAIL_KEY, $user->getEmail());
                $this->addFlash('warning', 'Your account was created, but we could not send the confirmation email right now. Please try resending it in a few minutes.');

                return $this->redirectToRoute('app_verify_resend');
            }

            $this->addFlash('success', 'Account created. Check your email for a confirmation link before logging in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/register.html.twig', ['form' => $form]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(
        Request $request,
        UserRepository $userRepository,
        EmailVerifier $emailVerifier,
    ): Response {
        $id = $request->query->getString('id');

        if ($id === '' || !Uuid::isValid($id)) {
            $this->addFlash('danger', 'Invalid verification link.');

            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find(Uuid::fromString($id));
        if ($user === null) {
            $this->addFlash('danger', 'Invalid verification link.');

            return $this->redirectToRoute('app_register');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Your email is already verified. Please log in.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('danger', $exception->getReason());

            return $this->redirectToRoute('app_register');
        }

        $this->addFlash('success', 'Your email address has been verified. You can now log in.');

        return $this->redirectToRoute('app_login');
    }

    /**
     * Resend the verification email. Reached directly, or by redirect when an
     * unverified account attempts to log in (UnverifiedLoginSubscriber). The
     * response is deliberately identical whether or not the account exists, so
     * the endpoint cannot be used to enumerate registered emails.
     */
    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['GET', 'POST'])]
    public function resendVerification(
        Request $request,
        UserRepository $userRepository,
        EmailVerifier $emailVerifier,
        RateLimiterFactoryInterface $resendVerificationLimiter,
    ): Response {
        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('resend_verification', $request->getPayload()->getString('_csrf_token'))) {
                $this->addFlash('danger', 'Invalid form token. Please try again.');

                return $this->redirectToRoute('app_verify_resend');
            }

            $email = mb_strtolower(trim($request->getPayload()->getString('email')));

            if ($email !== '') {
                $limiter = $resendVerificationLimiter->create($email . '|' . $request->getClientIp());

                if (!$limiter->consume()->isAccepted()) {
                    $this->addFlash('warning', 'Too many requests. Please wait before requesting another email.');

                    return $this->redirectToRoute('app_verify_resend');
                }

                $user = $userRepository->findOneBy(['email' => $email]);
                if ($user !== null && !$user->isVerified()) {
                    // Send result deliberately ignored: reporting a provider failure
                    // only when the account exists would leak which emails are
                    // registered. The failure is logged; the flash stays identical.
                    $this->sendConfirmationEmail($emailVerifier, $user);
                }
            }

            $session->remove(UnverifiedLoginSubscriber::SESSION_EMAIL_KEY);
            $this->addFlash('success', 'If an unverified account exists for that address, a new confirmation link has been sent.');

            return $this->redirectToRoute('app_verify_resend');
        }

        return $this->render('auth/resend_verification.html.twig', [
            'prefill_email' => $session->get(UnverifiedLoginSubscriber::SESSION_EMAIL_KEY, ''),
        ]);
    }

    private function sendConfirmationEmail(EmailVerifier $emailVerifier, User $user): bool
    {
        return $emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->to($user->getEmail())
                ->subject('Confirm your email address')
                ->htmlTemplate('auth/email/confirmation_email.html.twig'),
        );
    }
}
