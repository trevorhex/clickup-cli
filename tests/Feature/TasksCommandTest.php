<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requests my tasks with ClickUp-style array params', function () {
    Http::fake(['*' => Http::response(['tasks' => [fakeTask()], 'last_page' => true])]);

    $this->artisan('tasks')
        ->expectsOutputToContain('Fix booking calendar')
        ->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, '/team/900/task?')
            && str_contains($url, 'assignees[]=42')
            && str_contains($url, 'include_closed=false')
            && $request->header('Authorization') === ['pk_test'];
    });
});

it('matches statuses partially and locally', function () {
    Http::fake(['*' => Http::response(['tasks' => [
        fakeTask(['id' => 'a1', 'name' => 'Fix booking calendar']),
        fakeTask(['id' => 'b2', 'name' => 'Write release notes', 'status' => ['status' => 'to do']]),
        fakeTask(['id' => 'c3', 'name' => 'Ship gift cards', 'status' => ['status' => 'review']]),
    ], 'last_page' => true])]);

    $this->artisan('tasks -s prog -s todo')
        ->expectsOutputToContain('Fix booking calendar')
        ->expectsOutputToContain('Write release notes')
        ->doesntExpectOutputToContain('Ship gift cards')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => ! str_contains(urldecode($request->url()), 'statuses[]'));
});

it('lists the statuses it saw when no status matches', function () {
    Http::fake(['*' => Http::response(['tasks' => [fakeTask()], 'last_page' => true])]);

    $this->artisan('tasks -s blocked')
        ->expectsOutputToContain('No matching tasks')
        ->expectsOutputToContain('Statuses found: in progress (add --closed for closed ones)')
        ->assertSuccessful();
});

it('filters by partial list name and search text locally', function () {
    Http::fake(['*' => Http::response(['tasks' => [
        fakeTask(['id' => 'a1', 'name' => 'Fix booking calendar']),
        fakeTask(['id' => 'b2', 'name' => 'Fix invoice totals', 'list' => ['name' => 'Billing']]),
        fakeTask(['id' => 'c3', 'name' => 'Spa menu copy']),
    ], 'last_page' => true])]);

    $this->artisan('tasks fix --list=spa')
        ->expectsOutputToContain('Fix booking calendar')
        ->doesntExpectOutputToContain('Fix invoice totals')
        ->doesntExpectOutputToContain('Spa menu copy')
        ->assertSuccessful();
});

it('follows pagination until the last page', function () {
    Http::fakeSequence()
        ->push(['tasks' => [fakeTask(['id' => 'p0', 'name' => 'First page task'])], 'last_page' => false])
        ->push(['tasks' => [fakeTask(['id' => 'p1', 'name' => 'Second page task'])], 'last_page' => true]);

    $this->artisan('tasks')
        ->expectsOutputToContain('First page task')
        ->expectsOutputToContain('Second page task')
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('rejects an unknown --due value', function () {
    Http::fake();

    $this->artisan('tasks --due=someday')->assertFailed();

    Http::assertNothingSent();
});
