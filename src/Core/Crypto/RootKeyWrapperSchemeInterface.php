<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A concrete wrapping scheme the {@see RoutingRootKeyWrapper} can dispatch to:
 * it names itself (the deployment setting SIGIL_ROOT_WRAPPER_ACTIVE picks one
 * for new wraps) and the leading byte every blob it produces carries, which
 * is how an old blob finds its way back to the scheme that made it.
 */
#[AutoconfigureTag('app.root_key_wrapper')]
interface RootKeyWrapperSchemeInterface extends RootKeyWrapperInterface
{
    /** Stable id, e.g. "env", "pkcs11". */
    public function id(): string;

    /** The single byte that prefixes every blob this scheme produces. */
    public function schemeByte(): string;
}
