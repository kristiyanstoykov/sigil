<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Document\Entity\Document;
use App\Document\Enum\DocumentDisplayStatus;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Repository\SigningRequestRepository;

/**
 * The document's lifecycle state including its signature requests, which
 * Document::getDisplayStatus() cannot see (the Document module does not depend
 * on Signing). Results are memoised per request.
 */
final class DocumentStatusResolver
{
    /** @var array<string, DocumentDisplayStatus> */
    private array $cache = [];

    public function __construct(private readonly SigningRequestRepository $requests)
    {
    }

    public function resolve(Document $document): DocumentDisplayStatus
    {
        return $this->cache[$document->getId()->toRfc4122()] ??= $this->compute($document);
    }

    private function compute(Document $document): DocumentDisplayStatus
    {
        $request = $this->requests->findLatestForDocument($document);

        if (null !== $request) {
            if ($request->isPending()) {
                return DocumentDisplayStatus::Pending;
            }

            // A missed deadline outranks the signatures that were collected: the
            // document is on the record as incomplete, not as finished.
            if (SigningRequestStatus::Expired === $request->getStatus()) {
                return DocumentDisplayStatus::Expired;
            }
        }

        return $document->getDisplayStatus();
    }
}
