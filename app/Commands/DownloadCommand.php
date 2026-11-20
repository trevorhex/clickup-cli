<?php

namespace App\Commands;

use App\Support\Format;
use App\Support\Parse;
use RuntimeException;

use function Laravel\Prompts\multiselect;

class DownloadCommand extends ClickUpCommand
{
    protected $signature = 'download
        {id : Task ID or custom ID}
        {files?* : Which attachments: their number in `cu show`, part of the file name, or the attachment ID (omit to pick)}
        {--a|all : Download every attachment}
        {--o|output=. : Directory to save into}
        {--f|force : Overwrite existing files instead of saving as "name (2).ext"}';

    protected $description = 'Download a task\'s attachments';

    protected function perform(): int
    {
        $task = $this->clickup->task($this->argument('id'), $this->config->get('workspace_id'));
        $attachments = $this->attachments($task);

        if ($attachments === []) {
            throw new RuntimeException("{$this->argument('id')} has no attachments.");
        }

        $picked = $this->pick($attachments);

        $dir = rtrim($this->option('output'), '/');
        $dir = $dir === '' ? '/' : $dir;
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true)) {
            throw new RuntimeException("Can't create {$dir}.");
        }

        $taken = [];
        foreach ($picked as $a) {
            $path = $this->destination($dir, $a, $taken);
            $taken[] = $path;

            $this->clickup->download($a['url'], $path);
            $this->line("  <fg=green>✔</> {$path} <fg=gray>".Format::bytes(filesize($path)).'</>');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array>  $attachments
     * @return array<int, array>
     */
    private function pick(array $attachments): array
    {
        $wanted = $this->argument('files');

        if ($this->option('all') || count($attachments) === 1 && $wanted === []) {
            return $attachments;
        }

        if ($wanted !== []) {
            // Each link works once, so never fetch the same attachment twice.
            return collect($wanted)->map(fn ($w) => $this->match($w, $attachments))->unique('id')->values()->all();
        }

        $labels = array_map(fn ($a) => $a['title'].'  '.Format::bytes($a['size'] ?? null), $attachments);

        if (! $this->input->isInteractive()) {
            throw new RuntimeException(
                "Say which attachments to download, or pass --all:\n  ".
                implode("\n  ", array_map(fn ($n, $l) => "{$n}. {$l}", array_keys($labels), $labels))
            );
        }

        $picked = multiselect('Which attachments?', $labels, required: true, scroll: 12);

        return array_map(fn ($n) => $attachments[$n], $picked);
    }

    /** @param  array<int, array>  $attachments */
    private function match(string $wanted, array $attachments): array
    {
        if (ctype_digit($wanted)) {
            return $attachments[(int) $wanted]
                ?? throw new RuntimeException("There's no attachment #{$wanted} (this task has ".count($attachments).').');
        }

        foreach ($attachments as $a) {
            if ($a['id'] === $wanted) {
                return $a;
            }
        }

        $hits = Parse::fuzzy($wanted, array_column($attachments, 'title', 'id'));

        return match (count($hits)) {
            1 => collect($attachments)->firstWhere('id', array_key_first($hits)),
            0 => throw new RuntimeException("No attachment matches \"{$wanted}\". Available: ".implode(', ', array_column($attachments, 'title'))),
            default => throw new RuntimeException("\"{$wanted}\" matches several attachments: ".implode(', ', $hits).'. Use its number from `cu show`.'),
        };
    }

    /**
     * A safe file name in $dir. Unless --force, never overwrite: add " (2)", " (3)"…
     * Several attachments with the same title in one run always get distinct names.
     */
    private function destination(string $dir, array $a, array $taken): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', basename($a['title'] ?? '')), ' .');
        $name = $name !== '' ? $name : $a['id'];

        $info = pathinfo($name);
        $stem = $info['filename'];
        $ext = isset($info['extension']) ? ".{$info['extension']}" : '';

        for ($n = 1; ; $n++) {
            $path = "{$dir}/".($n === 1 ? $name : "{$stem} ({$n}){$ext}");
            $exists = file_exists($path) && ! $this->option('force');

            if (! $exists && ! in_array($path, $taken, true)) {
                return $path;
            }
        }
    }
}
