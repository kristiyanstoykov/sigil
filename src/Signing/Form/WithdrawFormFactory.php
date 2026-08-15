<?php

declare(strict_types=1);

namespace App\Signing\Form;

use App\Signing\Entity\SigningRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Builds the withdraw form for one request on the Sent tab. Same reasoning as
 * DeclineFormFactory: the tab renders one form per card, so each needs its own
 * name and its own CSRF token id.
 *
 * The document page has its own withdraw button on the same operation
 * (SigningRequestController::cancel), keyed by document rather than request -
 * two pages, two return targets, one service call underneath.
 */
final readonly class WithdrawFormFactory
{
    public function __construct(private FormFactoryInterface $forms) {}

    /**
     * @return FormInterface<mixed>
     */
    public function create(SigningRequest $request): FormInterface
    {
        return $this->forms->createNamed(
            self::name($request),
            CancelSigningRequestForm::class,
            null,
            ['csrf_token_id' => 'withdraw-signing-request-'.$request->getId()->toRfc4122()],
        );
    }

    /** Base32 of the UUID: a valid HTML name fragment, unlike the RFC 4122 form. */
    private static function name(SigningRequest $request): string
    {
        return 'withdraw_'.substr($request->getId()->toBase32(), 0, 12);
    }
}
