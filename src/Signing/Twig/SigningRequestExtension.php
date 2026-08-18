<?php

declare(strict_types=1);

namespace App\Signing\Twig;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Controller\SigningRequestController;
use App\Signing\Entity\SigningRequest;
use App\Signing\Form\CancelSigningRequestForm;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSignatories;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The signing panel on the document page, exposed to Twig so the Document module
 * does not have to depend on this one to render it.
 */
final class SigningRequestExtension extends AbstractExtension
{
    public function __construct(
        private readonly SigningRequestRepository $requests,
        private readonly FormFactoryInterface $forms,
        private readonly Security $security,
        private readonly DocumentSignatories $signatories,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_signing_request', $this->pending(...)),
            new TwigFunction('signing_cancel_form', $this->cancelForm(...)),
            new TwigFunction('signing_requests_for_me', $this->forMe(...)),
            new TwigFunction('signing_turns_for_me', $this->turnsForMe(...)),
            new TwigFunction('can_request_signatures', $this->canRequest(...)),
            new TwigFunction('document_signatures', $this->signatures(...)),
        ];
    }

    /**
     * A document gets one signature request in its life. Closed counts: the
     * receipt sealed for it names a fixed audience and outcome, and a second
     * queue over the same file would contradict it.
     */
    public function canRequest(Document $document): bool
    {
        return null === $this->requests->findLatestForDocument($document);
    }

    /**
     * Everyone who has actually signed this document, oldest signature first.
     * The logic lives in DocumentSignatories - the send path needs the same
     * answer to refuse a signer who has already signed.
     *
     * @return list<array{user: User, signedAt: \DateTimeImmutable, versionNumber: int, viaRequest: bool}>
     */
    public function signatures(Document $document): array
    {
        return $this->signatories->signatures($document);
    }

    /**
     * The subset of forMe() where it is actually this user's turn - what the
     * sidebar badge counts. A request sitting behind three other signers is not
     * work the badge should claim.
     */
    public function turnsForMe(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return \count(array_filter($this->forMe(), static fn (SigningRequest $r): bool => $r->isTurnOf($user)));
    }

    /**
     * Open requests the current user is listed on - their signing inbox.
     *
     * @return list<SigningRequest>
     */
    public function forMe(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->requests->findPendingForSigner($user) : [];
    }

    public function pending(Document $document): ?SigningRequest
    {
        return $this->requests->findPendingForDocument($document);
    }

    public function cancelForm(Document $document): FormView
    {
        return $this->forms
            ->create(CancelSigningRequestForm::class, null, [
                'csrf_token_id' => SigningRequestController::cancelTokenId($document),
            ])
            ->createView();
    }
}
