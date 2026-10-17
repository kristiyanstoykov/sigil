<?php

declare(strict_types=1);

namespace App\TempDownload\Scheduler;

use App\TempDownload\Message\SweepTempDownloads;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Consumed by the worker as the `scheduler_temp_download` transport. The sweep
 * is cleanup only: expiry is already enforced when a download is taken.
 */
#[AsSchedule('temp_download')]
final class TempDownloadSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('15 minutes', new SweepTempDownloads()));
    }
}
