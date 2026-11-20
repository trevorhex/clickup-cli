<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeAttachment(string $id, string $title, string $body, array $extra = []): array
{
    return [
        'id' => $id, 'title' => $title, 'size' => strlen($body), 'date' => '1700000000000', 'deleted' => false,
        'url' => "https://t900.p.clickup-attachments.com/t900/{$id}/".rawurlencode($title).'?authz_token=signed',
        ...$extra,
    ];
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/cu-dl-'.uniqid();

    Http::fake([
        '*/task/abc123?*' => Http::response(fakeTask(['attachments' => [
            fakeAttachment('a1', 'report.pdf', 'PDF'),
            fakeAttachment('a2', 'image.png', 'PNG1'),
            fakeAttachment('a3', 'image.png', 'PNG2'),
            fakeAttachment('a4', 'gone.txt', 'x', ['deleted' => true]),
        ]])),
        '*/task/solo?*' => Http::response(fakeTask(['id' => 'solo', 'attachments' => [fakeAttachment('s1', '../../etc/notes.txt', 'NOTES')]])),
        '*/task/empty?*' => Http::response(fakeTask(['id' => 'empty', 'attachments' => []])),
        '*clickup-attachments.com/t900/a1/*' => Http::response('PDF'),
        '*clickup-attachments.com/t900/a2/*' => Http::response('PNG1'),
        '*clickup-attachments.com/t900/a3/*' => Http::response('PNG2'),
        '*clickup-attachments.com/t900/s1/*' => Http::response('NOTES'),
    ]);
});

it('downloads attachments by number and name, without sending the API token', function () {
    $this->artisan("download abc123 1 image -o {$this->dir}")
        ->expectsOutputToContain('matches several attachments')
        ->assertFailed();

    $this->artisan("download abc123 1 3 -o {$this->dir}")->assertSuccessful();

    expect(file_get_contents("{$this->dir}/report.pdf"))->toBe('PDF')
        ->and(file_get_contents("{$this->dir}/image.png"))->toBe('PNG2');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'clickup-attachments') && $r->hasHeader('Authorization'));
});

it('downloads everything with --all, never overwriting', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/report.pdf", 'mine');

    $this->artisan("download abc123 --all -o {$this->dir}")->assertSuccessful();

    expect(file_get_contents("{$this->dir}/report.pdf"))->toBe('mine')
        ->and(file_get_contents("{$this->dir}/report (2).pdf"))->toBe('PDF')
        ->and(file_get_contents("{$this->dir}/image.png"))->toBe('PNG1')
        ->and(file_get_contents("{$this->dir}/image (2).png"))->toBe('PNG2')
        ->and(file_exists("{$this->dir}/gone.txt"))->toBeFalse();
});

it('overwrites with --force', function () {
    mkdir($this->dir);
    file_put_contents("{$this->dir}/report.pdf", 'mine');

    $this->artisan("download abc123 report --force -o {$this->dir}")->assertSuccessful();

    expect(file_get_contents("{$this->dir}/report.pdf"))->toBe('PDF');
});

it('downloads a lone attachment without asking, keeping it inside the output directory', function () {
    $this->artisan("download solo -o {$this->dir}")->assertSuccessful();

    expect(file_get_contents("{$this->dir}/notes.txt"))->toBe('NOTES');
});

it('lists the choices when it can\'t ask', function () {
    $this->artisan("download abc123 -o {$this->dir} --no-interaction")
        ->expectsOutputToContain('3. image.png')
        ->assertFailed();
});

it('says when a task has no attachments', function () {
    $this->artisan('download empty')
        ->expectsOutputToContain('has no attachments')
        ->assertFailed();
});

it('lists attachments in cu show', function () {
    Http::fake(['*/task/abc123/comment' => Http::response(['comments' => []])]);

    $this->artisan('show abc123')
        ->expectsOutputToContain('Attachments (3)')
        ->expectsOutputToContain('2. image.png')
        ->doesntExpectOutputToContain('gone.txt')
        ->assertSuccessful();
});
