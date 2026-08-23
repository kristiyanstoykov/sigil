<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The due-date countdown counts calendar days, not 24-hour blocks.
 *
 * A deadline is minted as "now + N days" and then read minutes or hours later,
 * so elapsed-time maths floors a 3-day deadline to "in 2 days" while the date
 * printed beside it still says the third day.
 */
final class DeadlineCountdownTest extends KernelTestCase
{
    private const TEMPLATE = '{% import "signing/_macros.html.twig" as s %}{{ s.due(d, true) }}';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deadlines(): iterable
    {
        // Hours past the minting moment, and what the countdown must still say.
        yield 'three days, read immediately' => ['+3 days', 'in 3 days'];
        yield 'three days, read six hours later' => ['+3 days -6 hours', 'in 3 days'];
        yield 'three days, read late in the evening' => ['+2 days +1 hour', 'in 2 days'];
        yield 'tomorrow' => ['+1 day', 'tomorrow'];
        yield 'later today' => ['+2 hours', 'today'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadlines')]
    public function testTheCountdownMatchesTheDateBesideIt(string $modifier, string $expected): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);

        $deadline = (new \DateTimeImmutable())->modify($modifier);
        $html = $twig->createTemplate(self::TEMPLATE)->render(['d' => $deadline]);

        self::assertStringContainsString($deadline->format('d M Y'), $html);
        self::assertStringContainsString($expected, $html);
    }

    public function testADeadlineThatHasPassedTodayReadsAsOverdue(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);

        // Same calendar day, already gone: the clock decides overdue, not the date.
        $html = $twig->createTemplate(self::TEMPLATE)
            ->render(['d' => (new \DateTimeImmutable())->modify('-1 hour')]);

        self::assertStringContainsString('overdue', $html);
        self::assertStringNotContainsString('today', $html);
    }
}
