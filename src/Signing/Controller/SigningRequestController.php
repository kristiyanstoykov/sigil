<?php

declare(strict_types=1);

namespace App\Signing\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use App\Signing\Entity\SigningRequest;
use App\Signing\Form\CancelSigningRequestForm;
use App\Signing\Form\CreateSigningRequestForm;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSignatories;
use App\Signing\Service\SignerEligibility;
use App\Signing\Service\SigningRequestService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/documents/{id}/request')]
class SigningRequestController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SigningRequestRepository $requests,
        private readonly SigningRequestService $service,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    /** The compose page: pick the signers, put them in order, send. */
    #[Route('', name: 'app_signing_request_new', methods: ['GET', 'POST'])]
    public function new(string $id, Request $request, SignerEligibility $eligibility, DocumentSignatories $signatories): Response
    {
        $document = $this->ownedDocument($id);

        // A document gets one request in its life, open or closed - see
        // SigningRequestService::create(), which enforces the same rule.
        $existing = $this->requests->findLatestForDocument($document);
        if (null !== $existing) {
            $this->addFlash('info', $existing->isPending()
                ? 'This document already has a signature request out.'
                : 'This document has already been through a signature request.');

            return $this->redirectToRoute('app_document_show', ['id' => $id]);
        }

        $form = $this->createForm(CreateSigningRequestForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $signers = $this->resolveSigners((string) $form->get(CreateSigningRequestForm::E_SIGNERS)->getData());
                $days = (int) $form->get(CreateSigningRequestForm::E_DEADLINE_DAYS)->getData();

                $this->service->create($document, $this->currentUser(), $signers, $this->deadlineIn($days));

                $this->addFlash('success', sprintf('Sent. %s will be asked to sign, in that order.', self::listNames($signers)));

                return $this->redirectToRoute('app_document_show', ['id' => $id]);
            } catch (DomainException $e) {
                $form->get(CreateSigningRequestForm::E_SIGNERS)->addError(new FormError($e->getMessage()));
            }
        }

        // ?include_me=1 comes from the purpose chooser's "Others sign, and I
        // sign too". It only seeds the list - the owner is an ordinary row from
        // there on, movable like any other, and create() re-checks eligibility.
        $preset = [];
        $me = $this->currentUser();
        $alreadySigned = $signatories->hasSigned($document, $me);
        if ($request->query->getBoolean('include_me') && !$form->isSubmitted() && !$alreadySigned) {
            $preset[] = [
                'email' => $me->getEmail(),
                'name' => $me->getFullName(),
                'ok' => $eligibility->isEligible($me),
                'reason' => $eligibility->reasonWhyNot($me),
            ];
        }

        return $this->renderForm($document, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, $preset, $alreadySigned);
    }

    /**
     * Look one signer up as they are added, so the list can show who they are and
     * whether they can sign before the request is sent. Same trade as sharing: an
     * authenticated user can probe whether an address is registered, and a picker
     * that silently accepts an unusable signer is worse.
     */
    #[Route('/lookup', name: 'app_signing_request_lookup', methods: ['POST'])]
    public function lookup(string $id, Request $request, SignerEligibility $eligibility, DocumentSignatories $signatories): JsonResponse
    {
        $document = $this->ownedDocument($id);

        $email = trim((string) $request->getPayload()->get('email'));
        $user = '' === $email ? null : $this->users->findOneByEmail($email);

        if (null === $user) {
            return $this->json(['ok' => false, 'reason' => 'No Sigil account uses that email address.']);
        }

        // Document-specific, so it cannot live in SignerEligibility: someone who
        // already signed this file must not be asked for a second signature.
        // create() enforces the same rule - this only says so earlier.
        $reason = $signatories->hasSigned($document, $user)
            ? $this->alreadySignedReason($user, $this->currentUser())
            : $eligibility->reasonWhyNot($user);

        return $this->json([
            'ok' => null === $reason,
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'reason' => $reason,
        ]);
    }

    private function alreadySignedReason(User $signer, User $viewer): string
    {
        return $signer->getId()->toRfc4122() === $viewer->getId()->toRfc4122()
            ? 'You have already signed this document.'
            : sprintf('%s has already signed this document.', $signer->getEmail());
    }

    #[Route('/cancel', name: 'app_signing_request_cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        $document = $this->ownedDocument($id);
        $pending = $this->requests->findPendingForDocument($document);

        $form = $this->createForm(CancelSigningRequestForm::class, null, [
            'csrf_token_id' => self::cancelTokenId($document),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid() || null === $pending) {
            // Nothing here is user-typed: a bad payload is a stale or tampered POST.
            throw $this->createNotFoundException();
        }

        try {
            $this->service->cancel($pending, $this->currentUser());
            $this->addFlash('success', 'The signature request was withdrawn.');
        } catch (DomainException $e) {
            // The form sits in a confirm modal, so a field error would render
            // into something the redirect has already closed.
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $id]);
    }

    /** The per-document CSRF token id for the cancel action. */
    public static function cancelTokenId(Document $document): string
    {
        return 'cancel-signing-request-'.$document->getId()->toRfc4122();
    }

    /**
     * The hidden field carries one email per line, in signing order.
     *
     * @return list<User>
     *
     * @throws DomainException on an address with no account behind it
     */
    private function resolveSigners(string $raw): array
    {
        $signers = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $email = trim($line);
            if ('' === $email) {
                continue;
            }

            $signers[] = $this->users->findOneByEmail($email)
                ?? throw new DomainException(sprintf('No Sigil account uses %s.', $email));
        }

        if ([] === $signers) {
            throw new DomainException('Add at least one signer.');
        }

        return $signers;
    }

    private function deadlineIn(int $days): \DateTimeImmutable
    {
        $days = max(1, min($days, SigningRequest::MAX_DEADLINE_DAYS));

        return \DateTimeImmutable::createFromInterface($this->clock->now())->modify(sprintf('+%d days', $days));
    }

    /**
     * @param FormInterface<mixed>                                              $form
     * @param list<array{email: string, name: string, ok: bool, reason: ?string}> $preset
     */
    private function renderForm(Document $document, FormInterface $form, int $status, array $preset = [], bool $alreadySigned = false): Response
    {
        return $this->render('signing/request_new.html.twig', [
            'document' => $document,
            'form' => $form,
            'preset' => $preset,
            'alreadySigned' => $alreadySigned,
        ], new Response(status: $status));
    }

    /** @param list<User> $signers */
    private static function listNames(array $signers): string
    {
        return implode(', ', array_map(static fn (User $u): string => $u->getFullName(), $signers));
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
