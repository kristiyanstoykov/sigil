<?php

declare(strict_types=1);

namespace App\Receipt\Twig;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Enum\ReceiptSource;
use App\Receipt\Repository\DeliveryReceiptKeyGrantRepository;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Signing\Entity\SigningRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The receipts panel on the document page, exposed to Twig so the Document
 * module does not have to depend on this one - the same seam Signing uses.
 */
final class ReceiptExtension extends AbstractExtension
{
    public function __construct(
        private readonly DeliveryReceiptRepository $receipts,
        private readonly DeliveryReceiptKeyGrantRepository $grants,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_receipts', $this->forDocument(...)),
            new TwigFunction('request_receipt', $this->forRequest(...)),
        ];
    }

    /**
     * The receipt sealed when this request closed, if the current user is on its
     * access list. The History tab's link to the evidence - and the only artifact
     * a decliner keeps, since closing revoked their document grant.
     */
    public function forRequest(SigningRequest $request): ?DeliveryReceipt
    {
        $user = $this->security->getUser();
        $receipt = $this->receipts->findForSource(ReceiptSource::SigningRequest, $request->getId());

        if (!$user instanceof User || null === $receipt) {
            return null;
        }

        return null !== $this->grants->findForReceiptAndUser($receipt, $user) ? $receipt : null;
    }

    /**
     * Receipts for this document that the current user may actually read. The
     * grants are the access list, so a signer sees only the requests they were
     * part of.
     *
     * @return list<DeliveryReceipt>
     */
    public function forDocument(Document $document): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            $this->receipts->findForDocument($document->getId()),
            fn (DeliveryReceipt $receipt): bool => null !== $this->grants->findForReceiptAndUser($receipt, $user),
        ));
    }
}
