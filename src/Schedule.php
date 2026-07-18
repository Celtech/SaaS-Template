<?php

declare(strict_types=1);

namespace App;

use App\Message\Billing\EnforceGracePeriodMessage;
use App\Message\Billing\ProcessExpiredTrialsMessage;
use App\Message\Webhook\RetryDueWebhookDeliveriesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                // Daily at 01:00 UTC — apply trial_expiry_behavior to all expired trials
                RecurringMessage::cron('0 1 * * *', new ProcessExpiredTrialsMessage()),
            )
            ->add(
                // Daily at 01:15 UTC — cancel past_due subscriptions that have passed the grace period
                RecurringMessage::cron('15 1 * * *', new EnforceGracePeriodMessage()),
            )
            ->add(
                // Every minute — redeliver webhook events whose retry backoff has elapsed
                RecurringMessage::every('1 minute', new RetryDueWebhookDeliveriesMessage()),
            )
        ;
    }
}
