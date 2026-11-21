<?php

declare(strict_types=1);

namespace App\Account\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Runs sigil:retention:sweep daily at 03:30 UTC on the worker
 * (`scheduler_retention`), clear of the host's 04:00 reboot. Stateful, so a
 * run missed while the worker restarted happens once when it is back.
 */
#[AsSchedule('retention')]
final readonly class RetentionSweepSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 day', new RunCommandMessage('sigil:retention:sweep'), from: '2026-01-01 03:30:00 UTC'))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
