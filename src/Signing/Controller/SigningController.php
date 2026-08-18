<?php

declare(strict_types=1);

namespace App\Signing\Controller;

use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use App\Signing\Exception\TokenPinRejectedException;
use App\Signing\Form\DeclineFormFactory;
use App\Signing\Form\SignDocumentForm;
use App\Signing\Repository\SigningRequestRepository;
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
        private readonly SigningRequestRepository $signingRequests,
        private readonly DeclineFormFactory $declineForms,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(
        string $id,
        Request $request,
        #[Autowire(service: 'limiter.pin_verification')]
        RateLimiterFactory $pinVerificationLimiter,
    ): Response {
        $user = $this->currentUser();
        $document = $this->signableDocument($id, $user);
        $signingRequest = $this->signingRequests->findPendingForDocument($document);

        // Delivered is terminal - there is nothing to decide and nothing to sign.
        if ($document->isDelivered()) {
            $this->addFlash('info', 'This document has been delivered, so it is final.');

            return $this->redirectToRoute('app_document_show', ['id' => $id]);
        }

        // Under a request the queue decides who may sign; only a signature that
        // nobody asked for is blocked by sign-once (both verbs, since a GET can
        // arrive from a stale tab and a POST from a replayed form).
        if (null === $signingRequest && $document->isSigned()) {
            $this->addFlash('info', 'This document has already been signed.');

            return $this->redirectToRoute('app_document_show', ['id' => $id]);
        }

        if (null !== $signingRequest && !$signingRequest->isTurnOf($user)) {
            return $this->render('signing/waiting.html.twig', [
                'document' => $document,
                'signingRequest' => $signingRequest,
            ]);
        }

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

                return $this->redirectToRoute('app_document_sign', ['id' => $id, 'purpose' => 'self']);
            }

            // DoS/brute-force shield in front of the ADR-008 Argon2id gate.
            if (!$pinVerificationLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many signing attempts - please try again later.');

                return $this->redirectToRoute('app_document_sign', ['id' => $id, 'purpose' => 'self']);
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
            } catch (DomainException $e) {
                // Covers a wrong PIN, a locked certificate, a signing failure and
                // a turn that is not this user's - all DomainException subclasses.
                $this->addFlash('danger', $e->getMessage());
            }

            // Redirect back (PRG) so the flash actually shows: Turbo ignores a
            // 200 response to a form submit, it wants a redirect or a 422.
            return $this->redirectToRoute('app_document_sign', ['id' => $id, 'purpose' => 'self']);
        }

        $response = $this->render('signing/sign.html.twig', [
            'document' => $document,
            'form' => $form,
            'hasUsableCertificate' => [] !== $usable,
            'signingRequest' => $signingRequest,
            // Only reachable here when it is this user's turn: the waiting view
            // returned above covers everyone else.
            'declineForm' => null !== $signingRequest ? $this->declineForms->create($signingRequest)->createView() : null,
            // Which panel of the purpose chooser to open. The chooser is pure
            // CSS, so a redirect back after a rejected PIN would otherwise land
            // on a closed modal with only a flash to explain itself.
            'openPurpose' => $form->isSubmitted() ? 'self' : $request->query->get('purpose'),
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

    /**
     * A picker label, not the whole subject. The stored DN is asn1crypto's
     * human_friendly form ("Common Name: ..., Organization: ..., Country: ...")
     * and runs to ~100 characters; a native select sizes its popup to the
     * longest option, so the full DN spilled out past the sign modal. The full
     * subject stays on the certificate page.
     */
    private function certificateLabel(Certificate $certificate): string
    {
        $dn = $certificate->getSubjectDn();
        $expires = $certificate->getNotAfter()->format('j M Y');

        $part = static function (string $key) use ($dn): ?string {
            return preg_match('/\b'.preg_quote($key, '/').': ([^,]+)/', $dn, $m) ? trim($m[1]) : null;
        };

        $name = $part('Common Name');
        if (null === $name) {
            // Unrecognised shape - keep it whole rather than guess at it.
            return sprintf('%s — expires %s', $dn, $expires);
        }

        // Organisation only when there is one: it is what separates two
        // certificates issued to the same person.
        $org = $part('Organization');

        return sprintf('%s%s · expires %s', $name, null !== $org ? ' ('.$org.')' : '', $expires);
    }

    /**
     * Who may open the sign page: the owner, or anyone listed on a pending
     * request for this document - including signers whose turn has not come up,
     * who get the waiting view rather than the form.
     */
    private function signableDocument(string $id, User $user): Document
    {
        $document = $this->documents->find($id);
        if (null === $document) {
            // 404, not 403: do not reveal that the id exists.
            throw $this->createNotFoundException();
        }

        if ($document->getOwner()->getId()->toRfc4122() === $user->getId()->toRfc4122()) {
            return $document;
        }

        $request = $this->signingRequests->findPendingForDocument($document);
        if (null === $request || null === $request->signerFor($user)) {
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
