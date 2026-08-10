<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Form\TwoFactorSetupForm;
use App\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly GoogleAuthenticatorInterface $googleAuthenticator,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/2fa/setup', name: 'app_2fa_setup')]
    public function setup(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isGoogleAuthenticatorEnabled()) {
            $this->addFlash('info', 'Two-factor authentication is already active on your account.');

            return $this->redirectToRoute('app_dashboard');
        }

        if ($user->getGoogleAuthenticatorSecret() === null) {
            $user->setGoogleAuthenticatorSecret($this->googleAuthenticator->generateSecret());
            $this->em->flush();
        }

        $form = $this->createForm(TwoFactorSetupForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $code */
            $code = $form->get(TwoFactorSetupForm::E_CODE)->getData();

            if ($this->googleAuthenticator->checkCode($user, $code)) {
                $user->enableTotp();
                $this->em->flush();

                $this->addFlash('success', 'Two-factor authentication is now active.');

                return $this->redirectToRoute('app_dashboard');
            }

            $form->get(TwoFactorSetupForm::E_CODE)->addError(
                new FormError('Invalid code. Please try again.'),
            );
        }

        $provisioningUri = $this->googleAuthenticator->getQRContent($user);

        $response = $this->render('auth/2fa_setup.html.twig', [
            'provisioning_uri' => $provisioningUri,
            'qr_code_data_uri' => $this->buildQrCodeDataUri($provisioningUri),
            'form' => $form,
        ]);

        // 422 on a rejected code: Turbo discards a 200 response to a form
        // submission, which used to mean the error had to travel as a flash.
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    // NOTE: self-service 2FA disable was intentionally removed - 2FA is mandatory
    // (see TwoFactorEnrollmentSubscriber). Any future reset must be an admin-only,
    // audited action, not a user-facing route.

    private function buildQrCodeDataUri(string $text): string
    {
        return (new Builder(
            writer: new SvgWriter(),
            data: $text,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 250,
            margin: 10,
        ))->build()->getDataUri();
    }
}
