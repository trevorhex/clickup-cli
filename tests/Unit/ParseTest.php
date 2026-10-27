<?php

use App\Support\Parse;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

it('parses priorities by name, prefix, or number', function () {
    expect(Parse::priority('urgent'))->toBe(1)
        ->and(Parse::priority('H'))->toBe(2)
        ->and(Parse::priority('4'))->toBe(4)
        ->and(Parse::priority('none'))->toBeNull();

    Parse::priority('whenever');
})->throws(InvalidArgumentException::class);

it('parses relative and absolute due dates at local noon', function () {
    CarbonImmutable::setTestNow('2026-10-27 08:00:00'); // a Tuesday

    expect(Parse::dueDate('today')->toDateTimeString())->toBe('2026-10-27 12:00:00')
        ->and(Parse::dueDate('+3d')->toDateString())->toBe('2026-10-30')
        ->and(Parse::dueDate('+1w')->toDateString())->toBe('2026-11-03')
        ->and(Parse::dueDate('fri')->toDateString())->toBe('2026-10-30')
        ->and(Parse::dueDate('2026-12-01')->toDateString())->toBe('2026-12-01')
        ->and(Parse::dueDate('none'))->toBeNull();
});

it('prefers exact, then prefix, then substring matches', function () {
    $options = ['a' => 'in progress', 'b' => 'in review', 'c' => 'review'];

    expect(Parse::fuzzy('review', $options))->toBe(['c' => 'review'])
        ->and(Parse::fuzzy('in', $options))->toBe(['a' => 'in progress', 'b' => 'in review'])
        ->and(Parse::fuzzy('prog', $options))->toBe(['a' => 'in progress'])
        ->and(Parse::fuzzy('zzz', $options))->toBe([]);
});
