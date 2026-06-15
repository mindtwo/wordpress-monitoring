<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Scheduler;

use Mindtwo\Monitoring\Data\TransportResult;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * WP-Cron wiring for the automatic push: keeps the recurring event in sync
 * with the configuration and executes the actual push when the hook fires.
 */
final class PushScheduler
{
    public const HOOK = 'mindtwo_monitoring_push';

    private WordPressConfigurationRepository $config;

    /** @var callable(): TransportResult */
    private $push;

    /**
     * @param  callable(): TransportResult  $push
     */
    public function __construct(
        private WordPressApi $wordPress,
        callable $push,
        ?WordPressConfigurationRepository $config = null
    ) {
        $this->config = $config ?? new WordPressConfigurationRepository($wordPress);
        $this->push = $push;
    }

    /**
     * Idempotent: schedules the recurring event when enabled and missing,
     * clears it when disabled. Safe to call on every request (init hook).
     */
    public function sync(): void
    {
        $scheduled = $this->wordPress->nextScheduled(self::HOOK) !== null;

        if (! $this->config->scheduleEnabled()) {
            if ($scheduled) {
                $this->wordPress->clearScheduledHook(self::HOOK);
            }

            return;
        }

        if (! $scheduled) {
            $recurrence = (string) $this->config->get('schedule_recurrence', 'daily');

            $this->wordPress->scheduleEvent(time(), $recurrence, self::HOOK);
        }
    }

    public function clear(): void
    {
        $this->wordPress->clearScheduledHook(self::HOOK);
    }

    /**
     * The cron callback: pushes a snapshot when enabled and configured.
     */
    public function run(): TransportResult
    {
        if (! $this->config->scheduleEnabled()) {
            return TransportResult::failed('Monitoring schedule is disabled.');
        }

        return ($this->push)();
    }
}
