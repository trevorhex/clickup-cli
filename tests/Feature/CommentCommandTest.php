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
