<?php

declare(strict_types=1);

namespace App\Core\Http;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Builds a Content-Disposition header that survives a non-ASCII filename.
 *
 * A raw `filename="Дипломна.pdf"` is not valid in an HTTP header, and stripping
 * the bytes that are not ASCII - which is what Sigil used to do - turns a
 * Cyrillic title into `__ _02.pdf`. RFC 6266/5987 solves this with two
 * parameters: `filename` for the ASCII fallback and `filename*` carrying the
 * real UTF-8 name percent-encoded. Every current browser prefers `filename*`.
 *
 * The fallback is transliterated rather than blanked, so the rare client that
 * uses it still gets something recognisable (Кристиян → Kristian).
 */
final class ContentDisposition
{
    public static function attachment(string $filename): string
    {
        return self::build(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
    }

    public static function inline(string $filename): string
    {
        return self::build(HeaderUtils::DISPOSITION_INLINE, $filename);
    }

    private static function build(string $disposition, string $filename): string
    {
        $filename = trim(str_replace(["\r", "\n", "\0"], '', $filename));
        if ('' === $filename) {
            $filename = 'document.pdf';
        }

        return HeaderUtils::makeDisposition($disposition, $filename, self::asciiFallback($filename));
    }

    /**
     * An ASCII-only name for the `filename` parameter. makeDisposition rejects
     * a fallback containing non-ASCII, `%`, `/` or `\`, and the slugger's output
     * is already limited to letters, digits and hyphens - so only the extension
     * has to be put back by hand.
     */
    private static function asciiFallback(string $filename): string
    {
        $extension = strtolower((string) pathinfo($filename, \PATHINFO_EXTENSION));
        $stem = (string) pathinfo($filename, \PATHINFO_FILENAME);

        $slug = (new AsciiSlugger())->slug($stem)->toString();
        if ('' === $slug) {
            $slug = 'document';
        }

        return 1 === preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $slug.'.'.$extension : $slug;
    }
}
