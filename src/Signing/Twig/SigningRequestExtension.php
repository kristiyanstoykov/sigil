<?php

declare(strict_types=1);

namespace App\Signing\Twig;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Controller\SigningRequestController;
use App\Signing\Entity\SigningRequest;
use App\Signing\Form\CancelSigningRequestForm;
use App\Signing\Repository\SigningRequestRepository;
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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_signing_request', $this->pending(...)),
            new TwigFunction('signing_cancel_form', $this->cancelForm(...)),
            new TwigFunction('signing_requests_for_me', $this->forMe(...)),
            new TwigFunction('is_pending_signer', $this->isPendingSigner(...)),
        ];
    }

    /**
     * Whether this person holds access because they are on the open request
     * rather than because the document was shared with them. Both look identical
     * from the grants, and only one of them is the owner's to revoke.
     */
    public function isPendingSigner(Document $document, User $user): bool
    {
        return null !== $this->pending($document)?->signerFor($user);
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
