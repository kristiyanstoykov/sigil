<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Core\Entity\User;
use App\Mailer\Service\Mailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Wraps the verify-email helper: builds the signed confirmation link, sends it,
 * and validates it on the way back. The signature is derived from the user id +
 * email + an expiry, so no token is stored server-side.
 */
final class EmailVerifier
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly Mailer $mailer,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return bool false when the mail provider rejected the send (logged, not thrown)
     */
    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email): bool
    {
        $signature = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => (string) $user->getId()],
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signature->getSignedUrl();
        $context['expiresAtMessageKey'] = $signature->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signature->getExpirationMessageData();
        $email->context($context);

        return $this->mailer->trySend($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface if the signature is invalid or expired
     */
    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            $user->getEmail(),
        );

        $user->setIsVerified(true);
        $this->entityManager->flush();
    }
}
