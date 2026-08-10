<?php

declare(strict_types=1);

namespace App\Certificate\Controller;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Certificate\Entity\Certificate;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Exception\InvalidPinException;
use App\Certificate\Form\CertificateActionForm;
use App\Certificate\Form\ChangePinForm;
use App\Certificate\Form\NewCertificateForm;
use App\Certificate\Form\UnlockCertificateForm;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
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
                $this->addFlash('danger', 'Too many issuance attempts - please try again later.');

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

        // One form per lifecycle action. Only the one whose button is rendered
        // gets used, but building all three keeps the template free of
        // conditionals it would otherwise duplicate.
        return $this->render('certificate/show.html.twig', [
            'certificate' => $certificate,
            'algorithm_label' => $algorithms->get($certificate->getAlgorithmId())->label(),
            'holdForm' => $this->actionForm($id, 'hold')->createView(),
            'resumeForm' => $this->actionForm($id, 'resume')->createView(),
            'revokeForm' => $this->actionForm($id, 'revoke', $certificate->getDisplayStatus()->isPinGuarded())->createView(),
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
                $this->addFlash('danger', 'Too many PIN attempts - please try again later.');

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

                    throw new CertificateLockedException('PIN integrity check failed - the certificate was locked as a precaution. Please re-issue it.');
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
                $this->addFlash('danger', 'Too many unlock attempts - please try again later.');

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

    #[Route('/{id}/hold', name: 'app_certificate_hold', methods: ['POST'])]
    public function hold(
        string $id,
        Request $request,
        AuditLoggerInterface $auditLogger,
        EntityManagerInterface $em,
        ClockInterface $clock,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $certificate = $this->ownedCertificate($id);

        $form = $this->actionForm($id, 'hold');
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $this->firstErrorMessage($form));

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        // allowOnHold: false → an already-held (or locked/revoked/expired)
        // certificate is rejected by the gate itself.
        if ($failure = $this->verifyPostedPin($certificate, $this->submittedPin($form), $pinVerificationLimiter, allowOnHold: false)) {
            return $failure;
        }

        $until = $this->utcNow($clock)->modify(sprintf('+%d hours', Certificate::HOLD_HOURS));
        $certificate->hold($until);
        $em->flush();

        $auditLogger->log(
            action: 'certificate.held',
            actor: $this->currentUser(),
            payload: ['heldUntil' => $until->format(\DateTimeInterface::ATOM)],
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
        );

        $this->addFlash('success', sprintf(
            'Certificate placed on hold until %s UTC. It resumes automatically, or earlier with your PIN.',
            $until->format('d M Y, H:i'),
        ));

        return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
    }

    #[Route('/{id}/resume', name: 'app_certificate_resume', methods: ['POST'])]
    public function resume(
        string $id,
        Request $request,
        AuditLoggerInterface $auditLogger,
        EntityManagerInterface $em,
        ClockInterface $clock,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $certificate = $this->ownedCertificate($id);

        $form = $this->actionForm($id, 'resume');
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $this->firstErrorMessage($form));

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        if (!$certificate->isOnHold($this->utcNow($clock))) {
            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        if ($failure = $this->verifyPostedPin($certificate, $this->submittedPin($form), $pinVerificationLimiter, allowOnHold: true)) {
            return $failure;
        }

        $certificate->releaseHold();
        $em->flush();

        // Warning like certificate.unlocked: signing capability was re-enabled.
        $auditLogger->log(
            action: 'certificate.hold_released',
            actor: $this->currentUser(),
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
            severity: AuditSeverity::Warning,
        );

        $this->addFlash('success', 'Certificate resumed - signing with it is enabled again.');

        return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
    }

    #[Route('/{id}/revoke', name: 'app_certificate_revoke', methods: ['POST'])]
    public function revoke(
        string $id,
        Request $request,
        CertificateIssuer $issuer,
        ClockInterface $clock,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $certificate = $this->ownedCertificate($id);

        // with_pin mirrors what the modal rendered (CertificateDisplayStatus::
        // isPinGuarded), so the submitted form matches the built one.
        $form = $this->actionForm($id, 'revoke', $certificate->getDisplayStatus()->isPinGuarded());
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $this->firstErrorMessage($form));

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        // An active (or held) certificate is only revocable with its PIN.
        // Locked and expired ones stay revocable without it: the locked state
        // exists precisely because the PIN is lost or desynced, and re-issuing
        // starts with revoking.
        //
        // This must stay in step with the with_pin above, or the modal posts no
        // PIN into a branch that demands one. It does: isWithinValidity() also
        // requires status Active, so Locked/Revoked fail it exactly as
        // isPinGuarded() excludes them (covered by
        // CertificateHoldTest::testLockedCertificateIsRevocableWithoutAPin).
        if ($certificate->isWithinValidity($this->utcNow($clock))
            && ($failure = $this->verifyPostedPin($certificate, $this->submittedPin($form), $pinVerificationLimiter, allowOnHold: true))) {
            return $failure;
        }

        try {
            $issuer->revoke($certificate, $this->currentUser(), 'revoked by owner');
            $this->addFlash('success', 'The certificate was revoked and its key destroyed.');
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_certificates');
    }

    /**
     * The confirm-modal form for a lifecycle action. The CSRF token id stays
     * per-action AND per-certificate, as it was when this was hand-rolled, so a
     * token minted for "hold" cannot be replayed against "revoke".
     */
    /** @return FormInterface<mixed> */
    private function actionForm(string $id, string $action, bool $withPin = true): FormInterface
    {
        return $this->createForm(CertificateActionForm::class, null, [
            'with_pin' => $withPin,
            'csrf_token_id' => $action.'-certificate-'.$id,
        ]);
    }

    /**
     * These actions live in a modal and redirect back, so their errors travel as
     * flashes: a field error would be rendered into a modal the redirect has
     * already closed.
     *
     * @param FormInterface<mixed> $form
     */
    private function firstErrorMessage(FormInterface $form): string
    {
        foreach ($form->getErrors(true) as $error) {
            return $error->getMessage();
        }

        return 'Invalid security token - please try again.';
    }

    /**
     * The submitted PIN, or '' for an action rendered without the field.
     *
     * @param FormInterface<mixed> $form
     */
    private function submittedPin(FormInterface $form): string
    {
        return $form->has(CertificateActionForm::E_PIN)
            ? (string) $form->get(CertificateActionForm::E_PIN)->getData()
            : '';
    }

    /**
     * Rate-limits and verifies the submitted PIN. Returns a redirect back to the
     * certificate on failure, null when the PIN checked out. A wrong PIN counts
     * toward the ADR-008 lockout like everywhere else.
     */
    private function verifyPostedPin(
        Certificate $certificate,
        string $pin,
        RateLimiterFactory $pinVerificationLimiter,
        bool $allowOnHold,
    ): ?Response {
        $id = $certificate->getId()->toRfc4122();

        if (!$pinVerificationLimiter->create($this->currentUser()->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('danger', 'Too many PIN attempts - please try again later.');

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        try {
            $this->pinGate->verify($certificate, $pin, $allowOnHold);
        } catch (InvalidPinException|CertificateLockedException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_certificate_show', ['id' => $id]);
        }

        return null;
    }

    private function utcNow(ClockInterface $clock): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($clock->now())->setTimezone(new \DateTimeZone('UTC'));
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
