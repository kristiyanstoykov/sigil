<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Enum\DocumentVersionKind;
use App\Signing\Repository\SigningRequestRepository;

/**
 * Who has actually signed a document, and therefore who cannot be asked to sign
 * it again. Two sources, because a signature can be made either way:
 *
 *  - through a request, where the SigningRequestSigner row carries the exact
 *    moment and the version it produced;
 *  - by the owner alone, which leaves only a Signed version behind.
 */
final class DocumentSignatories
{
    public function __construct(private readonly SigningRequestRepository $requests)
    {
    }

    /**
     * Every signature on the document, oldest first.
     *
     * @return list<array{user: User, signedAt: \DateTimeImmutable, versionNumber: int, viaRequest: bool}>
     */
    public function signatures(Document $document): array
    {
        $rows = [];
        $claimed = [];

        foreach ($this->requests->findAllForDocument($document) as $request) {
            foreach ($request->orderedSigners() as $signer) {
                $version = $signer->getVersion();
                $signedAt = $signer->getSignedAt();
                if (null === $version || null === $signedAt) {
                    continue;
                }
                $claimed[$version->getId()->toRfc4122()] = true;
                $rows[] = [
                    'user' => $signer->getUser(),
                    'signedAt' => $signedAt,
                    'versionNumber' => $version->getVersionNumber(),
                    'viaRequest' => true,
                ];
            }
        }

        foreach ($document->getVersions() as $version) {
            if (DocumentVersionKind::Signed !== $version->getKind()
                || isset($claimed[$version->getId()->toRfc4122()])) {
                continue;
            }
            // Only the owner can sign a document outside a request.
            $rows[] = [
                'user' => $document->getOwner(),
                'signedAt' => $version->getCreatedAt(),
                'versionNumber' => $version->getVersionNumber(),
                'viaRequest' => false,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['signedAt'] <=> $b['signedAt']);

        return $rows;
    }

    /** Whether this user's signature is already on the document. */
    public function hasSigned(Document $document, User $user): bool
    {
        $id = $user->getId()->toRfc4122();
        foreach ($this->signatures($document) as $row) {
            if ($row['user']->getId()->toRfc4122() === $id) {
                return true;
            }
        }

        return false;
    }
}
