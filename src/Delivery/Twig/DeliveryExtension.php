<?php

declare(strict_types=1);

namespace App\Delivery\Twig;

use App\Core\Entity\User;
use App\Delivery\Entity\Delivery;
use App\Delivery\Repository\DeliveryRepository;
use App\Document\Entity\Document;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The deliveries panel on the document page and the Role column on the list,
 * exposed to Twig so the Document module does not have to depend on this one -
 * the same seam Signing and Receipt use.
 */
final class DeliveryExtension extends AbstractExtension
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_deliveries', $this->forDocument(...)),
            new TwigFunction('was_delivered_to_me', $this->wasDeliveredToMe(...)),
        ];
    }

    /**
     * Every delivery of this document. Only the owner sees the panel, so this is
     * not filtered by recipient - a recipient sees their own copy in the list.
     *
     * @return list<Delivery>
     */
    public function forDocument(Document $document): array
    {
        return $this->deliveries->findForDocument($document);
    }

    /**
     * Whether the current user holds this document because it was served on them,
     * as against being on a signature request. Both look identical from the
     * grants, and the Role column is what tells them apart.
     */
    public function wasDeliveredToMe(Document $document): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->deliveries->wasServed($document, $user);
    }
}
