<?php

namespace App\Commands;

use App\Support\Format;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Formatter\OutputFormatter;

class ShowCommand extends ClickUpCommand
{
    protected $signature = 'show
        {id : Task ID (e.g. 868hyw6e4) or custom ID (e.g. UAT-20)}
        {--c|comments=5 : How many recent comments to show (0 for none)}
        {--o|open : Open the task in your browser instead}
        {--json : Output raw JSON}';

    protected $description = 'Show a task with its description, subtasks, checklists and comments';

    protected function perform(): int
    {
        $task = $this->clickup->task($this->argument('id'), $this->config->get('workspace_id'));

        if ($this->option('open')) {
            Process::run(['open', $task['url']]);
            $this->line("  Opened {$task['url']}");

            return self::SUCCESS;
        }

        $limit = (int) $this->option('comments');
        $comments = $limit > 0 ? array_reverse(array_slice($this->clickup->comments($task['id']), 0, $limit)) : [];

        if ($this->option('json')) {
            $this->line(json_encode([...$task, 'comments' => $comments], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->header($task);
        $this->description($task);
        $this->subtasks($task);
        $this->checklists($task);
        $this->comments($comments);

        return self::SUCCESS;
    }

    private function header(array $t): void
    {
        $location = implode(' › ', array_filter([
            ($t['folder']['hidden'] ?? true) ? null : ($t['folder']['name'] ?? null),
            $t['list']['name'] ?? null,
        ]));

        $assignees = collect($t['assignees'] ?? [])->pluck('username')->implode(', ') ?: '<fg=gray>unassigned</>';
        $tags = collect($t['tags'] ?? [])->pluck('name')->map(fn ($n) => "#{$n}")->implode(' ');

        $this->newLine();
        $this->line('  <options=bold>'.OutputFormatter::escape($t['name']).'</>');
        $this->line('  <fg=gray>'.Format::taskId($t)." · {$t['url']}</>");
        $this->newLine();

        $rows = [
            'Status' => Format::status($t['status']),
            'Priority' => Format::priority($t['priority'] ?? null) ?: '<fg=gray>none</>',
            'Due' => Format::due($t['due_date'] ?? null) ?: '<fg=gray>none</>',
            'List' => $location,
            'Assignees' => $assignees,
            'Tags' => $tags,
            'Parent' => $t['parent'] ?? null,
            'Created' => Format::ago($t['date_created'] ?? null).' by '.($t['creator']['username'] ?? '?'),
            'Updated' => Format::ago($t['date_updated'] ?? null),
        ];

        foreach (array_filter($rows) as $label => $value) {
            $this->line(sprintf('  <fg=gray>%-10s</> %s', $label, $value));
        }
    }

    private function description(array $t): void
    {
        $text = trim($t['markdown_description'] ?? $t['text_content'] ?? $t['description'] ?? '');

        if ($text === '') {
            return;
        }

        $this->section('Description');
        foreach (explode("\n", $text) as $line) {
            $this->line('  '.OutputFormatter::escape($line));
        }
    }

    private function subtasks(array $t): void
    {
        if (empty($t['subtasks'])) {
            return;
        }

        $this->section('Subtasks ('.count($t['subtasks']).')');
        foreach ($t['subtasks'] as $sub) {
            $this->line('  '.Format::status($sub['status']).'  '.OutputFormatter::escape($sub['name'])."  <fg=gray>{$sub['id']}</>");
        }
    }

    private function checklists(array $t): void
    {
        foreach ($t['checklists'] ?? [] as $checklist) {
            $items = $checklist['items'] ?? [];
            $done = count(array_filter($items, fn ($i) => $i['resolved']));

            $this->section("{$checklist['name']} ({$done}/".count($items).')');
            foreach ($items as $item) {
                $this->line('  '.($item['resolved'] ? '<fg=green>☑</>' : '☐').' '.OutputFormatter::escape($item['name']));
            }
        }
    }

    private function comments(array $comments): void
    {
        if ($comments === []) {
            return;
        }

        $this->section('Recent comments');
        foreach ($comments as $c) {
            $who = $c['user']['username'] ?? 'someone';
            $this->line("  <fg=cyan>{$who}</> <fg=gray>".Format::ago($c['date'] ?? null)." · {$c['id']}</>");
            foreach (explode("\n", trim($c['comment_text'] ?? '')) as $line) {
                $this->line('    '.OutputFormatter::escape($line));
            }
            $this->newLine();
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=yellow>── {$title} ──</>");
    }
}
