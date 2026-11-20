<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/task/abc123/comment' => fn (Request $r) => $r->method() === 'POST'
            ? Http::response(['id' => '9003'])
            : Http::response(['comments' => [
                ['id' => '9002', 'comment_text' => 'Heather\'s', 'user' => ['id' => 7]],
                ['id' => '9001', 'comment_text' => 'Mine', 'user' => ['id' => 42]],
            ]]),
        '*/comment/*' => Http::response([]),
    ]);
});

it('posts Markdown as rich-text blocks', function () {
    $this->artisan('comment abc123 "### Fixed:"')
        ->expectsOutputToContain('Comment 9003 added')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['comment'][1] === ['text' => "\n", 'attributes' => ['header' => 3]]);
});

it('reads the comment from a file', function () {
    $file = tempnam(sys_get_temp_dir(), 'cu');
    file_put_contents($file, "- Paid \$446.00\n");

    $this->artisan("comment abc123 -f {$file}")->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['comment'][0]['text'] === 'Paid $446.00');
});

it('edits a comment by ID', function () {
    $this->artisan('comment abc123 "Updated" -e 8888')
        ->expectsOutputToContain('Comment 8888 updated')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/comment/8888')
        && $r['comment'][0]['text'] === 'Updated');
});

it('edits my newest comment with --edit=last, skipping other people\'s', function () {
    $this->artisan('comment abc123 "Updated" --edit=last')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/comment/9001'));
});

it('tags @mentioned people who can see the task', function () {
    Http::fake([
        '*/task/abc123?*' => Http::response(fakeTask()),
        '*/list/1/member' => Http::response(['members' => [
            ['id' => 7, 'username' => 'Heather Worthan', 'email' => 'heather@example.com'],
            ['id' => 8, 'username' => 'Ravil Gainullov', 'email' => 'info@ifrond.com'],
        ]]),
    ]);

    $this->artisan('comment abc123 "@heather can you check? cc @ravil, not trevor@example.com or `@code`"')
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && array_slice($r['comment'], 0, 4) === [
        ['type' => 'tag', 'user' => ['id' => 7], 'text' => '@Heather Worthan'],
        ['text' => ' can you check? cc ', 'attributes' => []],
        ['type' => 'tag', 'user' => ['id' => 8], 'text' => '@Ravil Gainullov'],
        ['text' => ', not trevor@example.com or ', 'attributes' => []],
    ]);
});

it('leaves unknown @handles as text and refuses ambiguous ones', function () {
    Http::fake([
        '*/task/abc123?*' => Http::response(fakeTask()),
        '*/list/1/member' => Http::response(['members' => [
            ['id' => 7, 'username' => 'Heather Worthan', 'email' => 'heather@example.com'],
            ['id' => 9, 'username' => 'Heather Lee', 'email' => 'hlee@example.com'],
        ]]),
    ]);

    $this->artisan('comment abc123 "@nobody hi"')
        ->expectsOutputToContain("@nobody doesn't match anyone")
        ->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['comment'][0] === ['text' => '@nobody', 'attributes' => []]);

    $this->artisan('comment abc123 "@heather hi"')
        ->expectsOutputToContain('@heather matches several people')
        ->assertFailed();

    $this->artisan('comment abc123 "@heatherlee hi"')->assertSuccessful();
});

it('skips the task lookup when there is nothing to mention', function () {
    $this->artisan('comment abc123 "Plain, no tags"')->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/task/abc123?'));
});

it('tags a typed full name once, without repeating the last name', function () {
    Http::fake([
        '*/task/abc123?*' => Http::response(fakeTask()),
        '*/list/1/member' => Http::response(['members' => [
            ['id' => 5, 'username' => 'Cameron Smith', 'email' => 'cameron@example.com'],
            ['id' => 6, 'username' => 'Cameron Diaz', 'email' => 'cd@example.com'],
            ['id' => 7, 'username' => 'Heather Worthan', 'email' => 'heather@example.com'],
        ]]),
    ]);

    // "@Cameron" alone is ambiguous here, but the full name settles it.
    $this->artisan('comment abc123 "@Cameron Smith updated it. @Heather Worthan, @heather and @Heather Smith"')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && array_slice($r['comment'], 0, 7) === [
        ['type' => 'tag', 'user' => ['id' => 5], 'text' => '@Cameron Smith'],
        ['text' => ' updated it. ', 'attributes' => []],
        ['type' => 'tag', 'user' => ['id' => 7], 'text' => '@Heather Worthan'],
        ['text' => ', ', 'attributes' => []],
        ['type' => 'tag', 'user' => ['id' => 7], 'text' => '@Heather Worthan'],
        ['text' => ' and ', 'attributes' => []],
        ['type' => 'tag', 'user' => ['id' => 7], 'text' => '@Heather Worthan'],
    ]);
});
