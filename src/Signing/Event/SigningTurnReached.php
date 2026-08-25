<?php

declare(strict_types=1);

namespace App\Signing\Event;

use App\Signing\Entity\SigningRequest;
use App\Signing\Entity\SigningRequestSigner;

/**
 * The queue reached $signer: they hold the turn and the grant that comes with it.
 *
 * Dispatched both when a request is sent (the first signer) and when a signature
 * hands the turn on, because to the person receiving it those are the same
 * moment. Mail and Notification each subscribe; the producer knows neither.
 */
final readonly class SigningTurnReached
{
    public function __construct(
        public SigningRequest $request,
        public SigningRequestSigner $signer,
    ) {
    }
}
