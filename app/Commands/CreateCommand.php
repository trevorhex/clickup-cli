<?php

namespace App\Commands;

use App\Support\Format;
use App\Support\Parse;
use Illuminate\Support\Facades\Process;
use RuntimeException;

use function Laravel\Prompts\search;
use function Laravel\Prompts\text;

class CreateCommand extends ClickUpCommand
{
    protected $signature = 'create
        {name? : Task name (prompted if omitted)}
        {--l|list= : List name (partial match) or ID; defaults to `cu lists --default`}
        {--s|status= : Initial status (partial match)}
        {--p|priority= : urgent, high, normal, or low}
        {--d|due= : today, tomorrow, fri, +3d, 2026-11-05…}
        {--a|assign=* : Assignee: me, a username, email, or user ID (default: me)}
        {--unassigned : Don\'t assign anyone}
        {--desc= : Description (Markdown)}
        {--parent= : Create as a subtask of this task ID}
        {--o|open : Open the new task in your browser}';

    protected $description = 'Create a task (assigned to you by default)';

    protected function perform(): int
    {
        $parent = $this->option('parent')
            ? $this->clickup->task($this->option('parent'), $this->config->get('workspace_id'))
            : null;

        $list = $this->targetList($parent);
        $name = $this->argument('name')
            ?? ($this->input->isInteractive() ? text('Task name', required: true) : null)
            ?? throw new RuntimeException('A task name is required.');

        $payload = ['name' => $name];

        if ($parent) {
            $payload['parent'] = $parent['id'];
        }

        if ($status = $this->option('status')) {
            $payload['status'] = $this->resolveStatus($status, $this->clickup->list($list['id'])['statuses'] ?? []);
        }

        if ($priority = $this->option('priority')) {
            $payload['priority'] = Parse::priority($priority);
        }

        if ($due = $this->option('due')) {
            $payload['due_date'] = Parse::dueDate($due)?->getTimestampMs();
            $payload['due_date_time'] = false;
        }

        if ($desc = $this->option('desc')) {
            $payload['markdown_content'] = $desc;
        }

        if (! $this->option('unassigned')) {
            $payload['assignees'] = $this->resolveUsers($this->option('assign') ?: ['me'], $list['id']);
        }

        $task = $this->clickup->createTask($list['id'], $payload);

        $this->line('  <fg=green>✔</> Created <fg=gray>'.Format::taskId($task).'</> in '.$list['path']);
        $this->line("    <fg=gray>{$task['url']}</>");

        if ($this->option('open')) {
            Process::run(['open', $task['url']]);
        }

        return self::SUCCESS;
    }

    /** --list, else the parent's list, else the saved default, else ask. */
    private function targetList(?array $parent): array
    {
        if ($query = $this->option('list')) {
            return $this->resolveList($query);
        }

        if ($parent) {
            return $this->resolveList((string) $parent['list']['id']);
        }

        if ($default = $this->config->get('default_list_id')) {
            return $this->resolveList($default);
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('No list given. Pass --list or set a default with `cu lists --default`.');
        }

        $lists = $this->hierarchy()->lists();
        $id = search(
            label: 'Which list?',
            options: fn (string $q) => $lists
                ->filter(fn ($l) => $q === '' || str_contains(mb_strtolower($l['path']), mb_strtolower($q)))
                ->pluck('path', 'id')
                ->all(),
            hint: 'Tip: `cu lists --default` skips this question.',
            scroll: 12,
        );

        return $lists->firstWhere('id', (string) $id);
    }
}
