<?php

declare(strict_types=1);

namespace App\Signing\Form;

use App\Signing\Entity\SigningRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Builds the decline form for one request, so every place that renders it (the
 * inbox cards, the sign page) and the action that handles it agree on the form
 * name and the CSRF token id.
 *
 * Per-request naming is not decoration: the inbox renders one form per card, and
 * a shared name would emit duplicate DOM ids and a single token replayable
 * against any request on the page.
 */
final readonly class DeclineFormFactory
{
    public function __construct(private FormFactoryInterface $forms) {}

    /**
     * @return FormInterface<mixed>
     */
    public function create(SigningRequest $request): FormInterface
    {
        return $this->forms->createNamed(
            self::name($request),
            DeclineSigningRequestForm::class,
            null,
            ['csrf_token_id' => 'decline-signing-request-'.$request->getId()->toRfc4122()],
        );
    }

    /** Base32 of the UUID: a valid HTML name fragment, unlike the RFC 4122 form. */
    private static function name(SigningRequest $request): string
    {
        return 'decline_'.substr($request->getId()->toBase32(), 0, 12);
    }
}
