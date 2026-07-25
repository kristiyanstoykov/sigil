<?php

declare(strict_types=1);

namespace App\Signing\Controller;

use App\Certificate\Entity\Certificate;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Exception\InvalidPinException;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use App\Signing\Exception\SigningException;
use App\Signing\Exception\TokenPinRejectedException;
use App\Signing\Form\SignDocumentForm;
use App\Signing\Service\DocumentSigner;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/documents/{id}/sign', name: 'app_document_sign', methods: ['GET', 'POST'])]
class SigningController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly CertificateRepository $certificates,
        private readonly DocumentSigner $signer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        string $id,
        Request $request,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $document = $this->ownedDocument($id);

        // Sign-once (both verbs): a GET lands here from a stale tab or a
        // bookmark, a POST from a replayed form. Neither may reach the signer.
        if ($document->isSigned()) {
            $this->addFlash('info', 'This document has already been signed.');

            return $this->redirectToRoute('app_document_show', ['id' => $id]);
        }

        $user = $this->currentUser();
        $usable = $this->usableCertificates($user);

        $choices = [];
        foreach ($usable as $certificate) {
            $choices[$this->certificateLabel($certificate)] = $certificate->getId()->toRfc4122();
        }

        $form = $this->createForm(SignDocumentForm::class, null, ['certificate_choices' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ([] === $usable) {
                $this->addFlash('danger', 'You need a usable certificate before you can sign.');

                return $this->redirectToRoute('app_document_sign', ['id' => $id]);
            }

            // DoS/brute-force shield in front of the ADR-008 Argon2id gate.
            if (!$pinVerificationLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many signing attempts - please try again later.');

                return $this->redirectToRoute('app_document_sign', ['id' => $id]);
            }

            /** @var string $certificateId */
            $certificateId = $form->get(SignDocumentForm::E_CERTIFICATE)->getData();
            /** @var string $pin */
            $pin = $form->get(SignDocumentForm::E_PIN)->getData();
            $certificate = $this->pickUsable($usable, $certificateId);

            try {
                $this->signer->sign($document, $certificate, $user, $pin);
                $this->addFlash('success', 'Document signed. A new signed version was added.');

                return $this->redirectToRoute('app_document_show', ['id' => $id]);
            } catch (TokenPinRejectedException) {
                $this->addFlash('danger', 'PIN integrity check failed - the certificate was locked as a precaution. Please re-issue it.');
            } catch (InvalidPinException|CertificateLockedException|SigningException $e) {
                $this->addFlash('danger', $e->getMessage());
            }

            // Redirect back (PRG) so the flash actually shows: Turbo ignores a
            // 200 response to a form submit, it wants a redirect or a 422.
            return $this->redirectToRoute('app_document_sign', ['id' => $id]);
        }

        $response = $this->render('signing/sign.html.twig', [
            'document' => $document,
            'form' => $form,
            'hasUsableCertificate' => [] !== $usable,
        ]);

        // A submitted-but-invalid form re-renders with its field errors; 422 so
        // Turbo replaces the page instead of discarding the response.
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    /**
     * @param list<Certificate> $usable
     */
    private function pickUsable(array $usable, string $id): Certificate
    {
        foreach ($usable as $certificate) {
            if ($certificate->getId()->toRfc4122() === $id) {
                return $certificate;
            }
        }

        // The choice list is built from $usable, so a mismatch is a tampered POST.
        throw $this->createNotFoundException();
    }

    /**
     * @return list<Certificate>
     */
    private function usableCertificates(User $user): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return array_values(array_filter(
            $this->certificates->findByUser($user),
            static fn (Certificate $c): bool => $c->isUsable($now),
        ));
    }

    private function certificateLabel(Certificate $certificate): string
    {
        return sprintf('%s — expires %s', $certificate->getSubjectDn(), $certificate->getNotAfter()->format('j M Y'));
    }

    private function ownedDocument(string $id): Document
    {
        $document = $this->documents->find($id);
        if (null === $document || $document->getOwner()->getId()->toRfc4122() !== $this->currentUser()->getId()->toRfc4122()) {
            // 404, not 403: do not reveal that the id exists.
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
