<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Turns human-friendly CLI input into ClickUp API values.
 */
class Parse
{
    public const PRIORITIES = ['urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];

    /** Values that mean "clear this field". */
    private const NONE = ['none', 'clear', 'null', '-'];

    /**
     * "urgent" / "u" / "1" → 1, "none" → null.
     */
    public static function priority(string $input): ?int
    {
        $input = strtolower(trim($input));

        if (in_array($input, self::NONE, true)) {
            return null;
        }

        if (in_array($input, ['1', '2', '3', '4'], true)) {
            return (int) $input;
        }

        foreach (self::PRIORITIES as $name => $value) {
            if (str_starts_with($name, $input)) {
                return $value;
            }
        }

        throw new InvalidArgumentException("Unknown priority \"{$input}\". Use urgent, high, normal, low, or none.");
    }

    /**
     * "today", "tomorrow", "fri", "next monday", "+3d", "2026-11-05" → a date; "none" → null.
     * Dates are pinned to local noon so they land on the same day in any ClickUp timezone.
     */
    public static function dueDate(string $input): ?CarbonImmutable
    {
        $input = strtolower(trim($input));

        if (in_array($input, self::NONE, true)) {
            return null;
        }

        if (preg_match('/^\+(\d+)([dw])$/', $input, $m)) {
            $date = CarbonImmutable::today()->addDays((int) $m[1] * ($m[2] === 'w' ? 7 : 1));
        } else {
            try {
                $date = CarbonImmutable::parse($input);
            } catch (Throwable) {
                throw new InvalidArgumentException("Couldn't understand date \"{$input}\". Try today, fri, +3d, or 2026-11-05.");
            }
        }

        return $date->setTime(12, 0);
    }

    /**
     * Pick the best match for $input from $options, ignoring case, spaces, dashes and
     * underscores ("todo" finds "to do"): exact, then prefix, then substring.
     * Returns every candidate at the best tier.
     *
     * @param  array<string|int, string>  $options  key => label
     * @return array<string|int, string>
     */
    public static function fuzzy(string $input, array $options): array
    {
        $normalize = fn (string $s) => preg_replace('/[\s_-]+/', '', mb_strtolower($s));
        $needle = $normalize($input);

        foreach ([
            fn ($label) => $label === $needle,
            fn ($label) => str_starts_with($label, $needle),
            fn ($label) => str_contains($label, $needle),
        ] as $test) {
            $hits = array_filter($options, fn ($label) => $test($normalize($label)));
            if ($hits !== []) {
                return $hits;
            }
        }

        return [];
    }
}
