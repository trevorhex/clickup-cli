<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Console formatting shared by the task commands.
 */
class Format
{
    public const PRIORITY_STYLE = ['urgent' => 'red;options=bold', 'high' => 'yellow', 'normal' => 'blue', 'low' => 'gray'];

    public static function status(array $status): string
    {
        $color = $status['color'] ?? null;

        return $color ? "<fg={$color}>●</> {$status['status']}" : $status['status'];
    }

    public static function priority(?array $priority): string
    {
        $name = $priority['priority'] ?? null;

        return $name
            ? '<fg='.(self::PRIORITY_STYLE[$name] ?? 'default').'>'.ucfirst($name).'</>'
            : '';
    }

    public static function taskId(array $task): string
    {
        return $task['custom_id'] ?? $task['id'];
    }

    /** Relative, colored due date: red when overdue, yellow for today/tomorrow. */
    public static function due(?string $ms): string
    {
        if (! $ms) {
            return '';
        }

        $due = self::fromMs($ms);
        $today = CarbonImmutable::today();
        $date = $due->format($due->year === $today->year ? 'M j' : 'M j, Y');

        return match (true) {
            $due->lt($today) => "<fg=red>{$date}</>",
            $due->isToday() => '<fg=yellow;options=bold>Today</>',
            $due->isTomorrow() => '<fg=yellow>Tomorrow</>',
            $due->lt($today->addDays(7)) => $due->format('D M j'),
            default => $date,
        };
    }

    /** "3 hours ago" style timestamp. */
    public static function ago(?string $ms): string
    {
        return $ms ? self::fromMs($ms)->diffForHumans() : '';
    }

    public static function fromMs(string|int $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs((int) $ms)->setTimezone(date_default_timezone_get());
    }
}
