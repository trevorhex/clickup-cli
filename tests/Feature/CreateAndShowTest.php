<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/team/900/space?*' => Http::response(['spaces' => [['id' => 's1', 'name' => 'Civana']]]),
        '*/team/900/shared' => Http::response(['shared' => ['tasks' => [], 'lists' => [], 'folders' => []]]),
        '*/space/s1/list?*' => Http::response(['lists' => [['id' => '10', 'name' => 'Backlog']]]),
        '*/space/s1/folder?*' => Http::response(['folders' => [[
            'id' => 'f1', 'name' => 'Spa',
            'lists' => [['id' => '1', 'name' => 'Spa Bookings'], ['id' => '2', 'name' => 'Spa Menu']],
        ]]]),
        '*/list/1/task' => Http::response(fakeTask(['id' => 'new1', 'url' => 'https://app.clickup.com/t/new1'])),
        '*/list/1' => Http::response(fakeStatuses()),
        '*/task/abc123/comment' => Http::response(['comments' => [
            ['comment_text' => 'Newest', 'user' => ['username' => 'Heather'], 'date' => '1700000300000'],
            ['comment_text' => 'Oldest', 'user' => ['username' => 'Trevor'], 'date' => '1700000000000'],
        ]]),
        '*/task/abc123?*' => Http::response(fakeTask([
            'markdown_description' => "Steps:\n1. Open <calendar>",
            'checklists' => [['name' => 'QA', 'items' => [
                ['name' => 'Desktop', 'resolved' => true],
                ['name' => 'Mobile', 'resolved' => false],
            ]]],
        ])),
    ]);
});

it('creates a task in a list found by partial name, assigned to me', function () {
    $this->artisan('create "Add gift cards" -l bookings -s todo -p high')
        ->expectsOutputToContain('Created new1 in Civana › Spa › Spa Bookings')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/list/1/task')
        && $r->data() === ['name' => 'Add gift cards', 'status' => 'to do', 'priority' => 2, 'assignees' => [42]]);
});

it('asks for a more specific name when several lists match', function () {
    $this->artisan('create "Something" -l spa --no-interaction')
        ->expectsOutputToContain('matches several lists')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('prints lists as a space › folder tree', function () {
    $this->artisan('lists')
        ->expectsOutputToContain('Spa/')
        ->expectsOutputToContain('Spa Bookings')
        ->expectsOutputToContain('Backlog')
        ->assertSuccessful();
});

it('includes lists shared with a guest, skipping archived ones', function () {
    // Replace the beforeEach stubs: this guest can't see any spaces.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/team/900/space?*' => Http::response(['spaces' => []]),
        '*/team/900/shared' => Http::response(['shared' => [
            'tasks' => [],
            'lists' => [['id' => '50', 'name' => 'Reservations', 'archived' => false]],
            'folders' => [['id' => 'f9', 'name' => 'Development Sprints', 'lists' => [
                ['id' => '51', 'name' => 'Sprint 33', 'archived' => false],
                ['id' => '52', 'name' => 'Sprint 1 (old)', 'archived' => true],
            ]]],
        ]]),
    ]);

    $this->artisan('lists')
        ->expectsOutputToContain('Shared with me')
        ->expectsOutputToContain('Reservations')
        ->expectsOutputToContain('Development Sprints/')
        ->expectsOutputToContain('Sprint 33')
        ->doesntExpectOutputToContain('Sprint 1 (old)')
        ->assertSuccessful();
});

it('shows a task with description, checklist, and comments oldest-first', function () {
    $this->artisan('show abc123')
        ->expectsOutputToContain('Fix booking calendar')
        ->expectsOutputToContain('1. Open <calendar>') // escaped, not eaten as a console tag
        ->expectsOutputToContain('QA (1/2)')
        ->expectsOutputToContain('Oldest')
        ->expectsOutputToContain('Newest')
        ->assertSuccessful();
});
