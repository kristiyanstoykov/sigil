<?php

declare(strict_types=1);

namespace App\Tests\Functional\Core;

use App\Account\Scheduler\RetentionSweepSchedule;
use App\Signing\Scheduler\SigningSweepSchedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The sweeps run on the worker's scheduler, not on cron. They are anchored to
 * the clock: the worker restarts every hour, and an interval counted from its
 * start would never come due.
 */
final class ScheduledSweepsTest extends KernelTestCase
{
    public function testTheSigningSweepRunsAtFivePastEveryHour(): void
    {
        self::assertSchedule(SigningSweepSchedule::class, 'sigil:signing:sweep', '2026-10-25 10:20:00', '2026-10-25 11:05:00');
    }

    public function testTheRetentionSweepRunsDailyBeforeTheRebootWindow(): void
    {
        self::assertSchedule(RetentionSweepSchedule::class, 'sigil:retention:sweep', '2026-10-25 10:20:00', '2026-10-26 03:30:00');
    }

    public function testAScheduledCommandRunsThroughTheBus(): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $context = $bus->dispatch(new RunCommandMessage('sigil:retention:sweep --dry-run'))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RunCommandContext::class, $context);
        self::assertSame(0, $context->exitCode);

        // The real command, not a stand-in: an option it lacks fails the message.
        $this->expectException(HandlerFailedException::class);
        $bus->dispatch(new RunCommandMessage('sigil:retention:sweep --no-such-option'));
    }

    /** @param class-string<ScheduleProviderInterface> $provider */
    private static function assertSchedule(string $provider, string $command, string $now, string $expectedNext): void
    {
        $schedule = static::getContainer()->get($provider);
        self::assertInstanceOf(ScheduleProviderInterface::class, $schedule);
        $messages = $schedule->getSchedule()->getRecurringMessages();
        self::assertCount(1, $messages);

        $message = $messages[0]->getProvider()->getMessages(new MessageContext('t', 't', $messages[0]->getTrigger(), new \DateTimeImmutable()));
        $first = iterator_to_array($message)[0] ?? null;
        self::assertInstanceOf(RunCommandMessage::class, $first);
        self::assertSame($command, $first->input);

        $next = $messages[0]->getTrigger()->getNextRunDate(new \DateTimeImmutable($now.' UTC'));
        self::assertEquals(new \DateTimeImmutable($expectedNext.' UTC'), $next);
    }
}
