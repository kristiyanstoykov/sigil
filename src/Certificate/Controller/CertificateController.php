<?php

declare(strict_types=1);

namespace App\Certificate\Controller;

use App\AuditLog\AuditLoggerInterface;
use App\Certificate\Entity\Certificate;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Exception\InvalidPinException;
use App\Certificate\Form\ChangePinForm;
use App\Certificate\Form\NewCertificateForm;
use App\Certificate\Form\UnlockCertificateForm;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/certificates')]
class CertificateController extends AbstractController
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly PinGate $pinGate,
    ) {
    }

    #[Route('', name: 'app_certificates', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('certificate/index.html.twig', [
            'certificates' => $this->certificates->findByUser($this->currentUser()),
            'max_certificates' => Certificate::MAX_PER_USER,
        ]);
    }

    #[Route('/new', name: 'app_certificate_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CertificateIssuer $issuer,
        #[Autowire(service: 'limiter.certificate_issue')]
        RateLimiterFactory $certificateIssueLimiter,
    ): Response {
        $user = $this->currentUser();
        $atLimit = $this->certificates->countActiveForUser($user) >= Certificate::MAX_PER_USER;

        $form = $this->createForm(NewCertificateForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !$atLimit) {
            if (!$certificateIssueLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many issuance attempts — please try again later.');

                return $this->redirectToRoute('app_certificate_new');
            }

            /** @var string $pin */
            $pin = $form->get(NewCertificateForm::E_PIN)->getData();

            try {
                $certificate = $issuer->issueForUser($user, $pin);
            } catch (DomainException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('app_certificate_new');
            }

            $this->addFlash('success', 'Your certificate was issued. The private key was generated inside the secure token and can never leave it.');

            return $this->redirectToRoute('app_certificate_show', ['id' => $certificate->getId()]);
        }

        return $this->render('certificate/new.html.twig', [
            'form' => $form,
            'at_limit' => $atLimit,
            'max_certificates' => Certificate::MAX_PER_USER,
            'subject_preview' => [
                'Full name' => trim($user->getFirstName().' '.$user->getLastName()),
                'Company' => $user->getCompany(),
                'Position' => $user->getPosition(),
                'Country' => 'Bulgaria',
            ],
        ]);
    }

    #[Route('/{id}', name: 'app_certificate_show', methods: ['GET'])]
    public function show(string $id, \App\Certificate\Algorithm\SignatureAlgorithmRegistry $algorithms): Response
    {
        $certificate = $this->ownedCertificate($id);

        return $this->render('certificate/show.html.twig', [
            'certificate' => $certificate,
            'algorithm_label' => $algorithms->get($certificate->getAlgorithmId())->label(),
        ]);
    }

    #[Route('/{id}/download', name: 'app_certificate_download', methods: ['GET'])]
    public function download(string $id): Response
    {
        $certificate = $this->ownedCertificate($id);

        return new Response($certificate->getCertificatePem(), Response::HTTP_OK, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => sprintf('attachment; filename="sigil-%s.crt"', $certificate->getSerialNumber()),
        ]);
    }

    #[Route('/{id}/change-pin', name: 'app_certificate_change_pin', methods: ['GET', 'POST'])]
    public function changePin(
        string $id,
        Request $request,
        Pkcs11TokenManager $tokens,
        AuditLoggerInterface $auditLogger,
        \Doctrine\ORM\EntityManagerInterface $em,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $certificate = $this->ownedCertificate($id);
        $user = $this->currentUser();

        $form = $this->createForm(ChangePinForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$pinVerificationLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many PIN attempts — please try again later.');

                return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
            }

            /** @var string $currentPin */
            $currentPin = $form->get(ChangePinForm::E_CURRENT_PIN)->getData();
            /** @var string $newPin */
            $newPin = $form->get(ChangePinForm::E_NEW_PIN)->getData();

            try {
                // ADR-008: hash-first gate, then token first, then DB hash.
                $this->pinGate->verify($certificate, $currentPin);

                try {
                    $tokens->changeUserPin($certificate->getTokenLabel(), $currentPin, $newPin);
                } catch (DomainException) {
                    // hash matched but the token refused → desync tripwire
                    $this->pinGate->reportTokenPinRejected($certificate);

                    throw new CertificateLockedException('PIN integrity check failed — the certificate was locked as a precaution. Please re-issue it.');
                }

                $certificate->setPinHash(password_hash($newPin, \PASSWORD_ARGON2ID));
                $em->flush();

                $auditLogger->log(
                    action: 'certificate.pin_changed',
                    actor: $user,
                    subjectType: 'Certificate',
                    subjectId: $certificate->getId()->toRfc4122(),
                );

                $this->addFlash('success', 'Your PIN was changed.');

                return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
            } catch (InvalidPinException|CertificateLockedException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('certificate/change_pin.html.twig', [
            'form' => $form,
            'certificate' => $certificate,
        ]);
    }

    #[Route('/{id}/unlock', name: 'app_certificate_unlock', methods: ['GET', 'POST'])]
    public function unlock(
        string $id,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        GoogleAuthenticatorInterface $googleAuthenticator,
        #[Autowire(service: 'limiter.certificate_unlock')]
        RateLimiterFactory $certificateUnlockLimiter,
    ): Response {
        $certificate = $this->ownedCertificate($id);
        $user = $this->currentUser();

        if (!$certificate->isLocked()) {
            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        $form = $this->createForm(UnlockCertificateForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$certificateUnlockLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many unlock attempts — please try again later.');

                return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
            }

            /** @var string $password */
            $password = $form->get(UnlockCertificateForm::E_PASSWORD)->getData();
            /** @var string $totpCode */
            $totpCode = $form->get(UnlockCertificateForm::E_TOTP_CODE)->getData();

            // ADR-008: both factors re-proven at unlock time
            if (!$passwordHasher->isPasswordValid($user, $password)
                || !$googleAuthenticator->checkCode($user, $totpCode)) {
                $this->addFlash('danger', 'Password or authenticator code incorrect.');
            } else {
                $this->pinGate->unlock($certificate);
                $this->addFlash('success', 'Certificate unlocked. Your PIN attempt counter was reset.');

                return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
            }
        }

        return $this->render('certificate/unlock.html.twig', [
            'form' => $form,
            'certificate' => $certificate,
        ]);
    }

    #[Route('/{id}/revoke', name: 'app_certificate_revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request, CertificateIssuer $issuer): Response
    {
        $certificate = $this->ownedCertificate($id);

        if (!$this->isCsrfTokenValid('revoke-certificate-'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token — please try again.');

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        try {
            $issuer->revoke($certificate, $this->currentUser(), 'revoked by owner');
            $this->addFlash('success', 'The certificate was revoked and its key destroyed.');
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_certificates');
    }

    private function ownedCertificate(string $id): Certificate
    {
        $certificate = $this->certificates->find($id);
        if (null === $certificate || $certificate->getUser()->getId()->toRfc4122() !== $this->currentUser()->getId()->toRfc4122()) {
            // 404, not 403: do not reveal that the id exists
            throw $this->createNotFoundException();
        }

        return $certificate;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
