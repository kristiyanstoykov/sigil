<?php

declare(strict_types=1);

namespace App\Signing\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Runs sigil:signing:sweep hourly on the worker (`scheduler_signing`). The
 * deadline is enforced at signing time; this only closes what is overdue.
 * Anchored to the clock and stateful, because the worker restarts every hour.
 */
#[AsSchedule('signing')]
final readonly class SigningSweepSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 hour', new RunCommandMessage('sigil:signing:sweep'), from: '2026-01-01 00:05:00 UTC'))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
