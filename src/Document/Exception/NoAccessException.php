<?php

declare(strict_types=1);

namespace App\Document\Exception;

use App\Core\Exception\DomainException;

/**
 * The user holds no grant on what they asked for. The one downloader failure
 * a controller answers with 404 (the id is not confirmed); every other cause -
 * a missing object, a backend outage, a decryption failure - is a 500 with the
 * exception logged, because a document that cannot be read is an incident,
 * not a document that does not exist.
 */
final class NoAccessException extends DomainException
{
}
