<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every Tabler icon Sigil names must exist in the vendored font: a missing one
 * renders as an empty box with no error anywhere.
 */
final class TablerIconsTest extends TestCase
{
    public function testEveryIconNamedInTemplatesAndSourceExistsInTheFont(): void
    {
        $root = \dirname(__DIR__, 3);
        $css = (string) file_get_contents($root.'/assets/able-pro/fonts/tabler-icons.css');

        $missing = [];
        $files = (new Finder())->files()->in([$root.'/templates', $root.'/src', $root.'/assets/controllers', $root.'/assets/behaviors']);
        foreach ($files as $file) {
            preg_match_all('/\bti-[a-z0-9]+(?:-[a-z0-9]+)*/', $file->getContents(), $m);
            foreach (array_unique($m[0]) as $icon) {
                if (!str_contains($css, '.'.$icon.':before')) {
                    $missing[] = $icon.' in '.$file->getRelativePathname();
                }
            }
        }

        self::assertSame([], $missing);
    }
}
