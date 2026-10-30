<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/task/abc123/comment' => Http::response(['id' => 1]),
        '*/task/abc123?*' => Http::response(fakeTask()),
        '*/task/def456?*' => Http::response(fakeTask(['id' => 'def456', 'name' => 'Second task'])),
        '*/task/*' => Http::response([]),
        '*/list/1/member' => Http::response(['members' => [
            ['id' => 7, 'username' => 'Heather Worthan', 'email' => 'heather@example.com'],
        ]]),
        '*/list/1' => Http::response(fakeStatuses()),
    ]);
});

function sentPut(string $id): ?array
{
    $hit = Http::recorded(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), "/task/{$id}"))->first();

    return $hit ? $hit[0]->data() : null;
}

it('matches a partial status name against the list\'s statuses', function () {
    $this->artisan('update abc123 -s rev')
        ->expectsOutputToContain('in progress → in review')
        ->assertSuccessful();

    expect(sentPut('abc123'))->toBe(['status' => 'in review']);
});

it('closes several tasks at once with --done, fetching statuses once', function () {
    $this->artisan('update abc123 def456 --done')->assertSuccessful();

    expect(sentPut('abc123'))->toBe(['status' => 'complete'])
        ->and(sentPut('def456'))->toBe(['status' => 'complete']);

    Http::assertSentCount(5); // 2 task lookups + 1 list lookup + 2 updates
});

it('sets priority, due date, and assignees, and posts a comment', function () {
    $this->artisan('update abc123 -p urgent -d 2026-11-05 -a heather -u me -c "Looks good"')
        ->assertSuccessful();

    $put = sentPut('abc123');
    expect($put['priority'])->toBe(1)
        ->and($put['due_date_time'])->toBeFalse()
        ->and(date('Y-m-d', intdiv($put['due_date'], 1000)))->toBe('2026-11-05')
        ->and($put['assignees'])->toBe(['add' => [7], 'rem' => [42]]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/task/abc123/comment')
        && $r['comment'] === [['text' => 'Looks good', 'attributes' => []], ['text' => "\n", 'attributes' => []]]);
});

it('rejects an unknown status and lists the valid ones', function () {
    $this->artisan('update abc123 -s shipped')
        ->expectsOutputToContain('Available: to do, in progress, in review, complete')
        ->assertFailed();

    expect(sentPut('abc123'))->toBeNull();
});

it('refuses to run with nothing to change', function () {
    $this->artisan('update abc123 def456')->assertFailed();

    Http::assertNothingSent();
});
