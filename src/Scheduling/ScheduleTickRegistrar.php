<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Idempotent Laravel scheduler wiring for social-automation:schedule-tick.
 * Does not dispatch campaigns itself — only registers the existing artisan command.
 */
final class ScheduleTickRegistrar
{
    public const COMMAND = 'social-automation:schedule-tick';

    public const EVENT_NAME = 'social-automation-schedule-tick';

    /** Mutex TTL (minutes) for withoutOverlapping under concurrent schedule:run. */
    public const OVERLAP_MINUTES = 5;

    /**
     * @return bool true when a new event was added; false when already present
     */
    public function register(Schedule $schedule): bool
    {
        if ($this->isRegistered($schedule)) {
            return false;
        }

        $schedule->command(self::COMMAND)
            ->everyMinute()
            ->withoutOverlapping(self::OVERLAP_MINUTES)
            ->name(self::EVENT_NAME);

        return true;
    }

    public function isRegistered(Schedule $schedule): bool
    {
        return $this->matchingEvents($schedule) !== [];
    }

    /**
     * @return list<\Illuminate\Console\Scheduling\Event>
     */
    public function matchingEvents(Schedule $schedule): array
    {
        $matches = [];
        foreach ($schedule->events() as $event) {
            $command = (string) ($event->command ?? '');
            $description = (string) ($event->description ?? '');
            if (str_contains($command, self::COMMAND) || str_contains($description, self::COMMAND)) {
                $matches[] = $event;
            }
        }

        return $matches;
    }
}
