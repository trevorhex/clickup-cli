<?php

namespace App\Commands;

use App\Support\Format;
use App\Support\Parse;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\select;

class UpdateCommand extends ClickUpCommand
{
    protected $signature = 'update
        {ids* : One or more task IDs}
        {--s|status= : New status (partial match, e.g. "prog" → "in progress")}
        {--done : Move to the list\'s closed status}
        {--p|priority= : urgent, high, normal, low, or none}
        {--d|due= : today, tomorrow, fri, +3d, 2026-11-05, or none}
        {--name= : Rename the task}
        {--desc= : Replace the description (Markdown)}
        {--a|assign=* : Add an assignee: me, a username, email, or user ID (repeatable)}
        {--u|unassign=* : Remove an assignee (repeatable)}
        {--c|comment= : Also add a comment}';

    protected $description = 'Update one or more tasks (status, priority, due date, assignees…)';

    /** @var array<string, array> List statuses, fetched once per list. */
    private array $statuses = [];

    protected function perform(): int
    {
        $ids = $this->argument('ids');
        $failed = 0;

        if (! $this->hasChanges() && ! (count($ids) === 1 && $this->input->isInteractive())) {
            throw new RuntimeException('Nothing to update. Pass --status, --done, --priority, --due, --assign, etc. (see `cu update --help`).');
        }

        foreach ($ids as $id) {
            try {
                $this->updateOne($id);
            } catch (RuntimeException $e) {
                $this->line("  <fg=red>✘</> <fg=gray>{$id}</> {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function updateOne(string $id): void
    {
        $task = $this->clickup->task($id, $this->config->get('workspace_id'));
        [$payload, $changes] = $this->hasChanges() ? $this->buildPayload($task) : $this->promptForStatus($task);

        if ($payload !== []) {
            $this->clickup->updateTask($task['id'], $payload);
        }

        if ($comment = $this->option('comment')) {
            $this->clickup->addComment($task['id'], $comment);
            $changes[] = 'comment added';
        }

        $name = OutputFormatter::escape(mb_strimwidth($task['name'], 0, 50, '…'));
        $this->line('  <fg=green>✔</> <fg=gray>'.Format::taskId($task)."</> {$name}");
        foreach ($changes as $change) {
            $this->line("      {$change}");
        }
    }

    /** @return array{0: array, 1: string[]} API payload and human-readable change lines */
    private function buildPayload(array $task): array
    {
        $payload = [];
        $changes = [];
        $listId = (string) $task['list']['id'];

        if ($this->option('done') || $this->option('status') !== null) {
            $statuses = $this->statusesFor($listId);
            $new = $this->option('done')
                ? $this->closedStatus($statuses)
                : $this->resolveStatus($this->option('status'), $statuses);

            $payload['status'] = $new;
            $changes[] = "status: {$task['status']['status']} → <options=bold>{$new}</>";
        }

        if (($priority = $this->option('priority')) !== null) {
            $payload['priority'] = Parse::priority($priority);
            $label = array_search($payload['priority'], Parse::PRIORITIES, true) ?: 'none';
            $changes[] = 'priority: '.($task['priority']['priority'] ?? 'none')." → <options=bold>{$label}</>";
        }

        if (($due = $this->option('due')) !== null) {
            $date = Parse::dueDate($due);
            $payload['due_date'] = $date?->getTimestampMs();
            $payload['due_date_time'] = false;
            $changes[] = 'due: <options=bold>'.($date?->format('D M j, Y') ?? 'none').'</>';
        }

        if (($name = $this->option('name')) !== null) {
            $payload['name'] = $name;
            $changes[] = 'renamed';
        }

        if (($desc = $this->option('desc')) !== null) {
            $payload['markdown_content'] = $desc;
            $changes[] = 'description replaced';
        }

        $add = $this->resolveUsers($this->option('assign'), $listId);
        $remove = $this->resolveUsers($this->option('unassign'), $listId);
        if ($add || $remove) {
            $payload['assignees'] = ['add' => $add, 'rem' => $remove];
            $changes[] = 'assignees: '.trim(($add ? '+'.count($add).' ' : '').($remove ? '-'.count($remove) : ''));
        }

        return [$payload, $changes];
    }

    /** No flags on a single task: just ask for the new status. */
    private function promptForStatus(array $task): array
    {
        $names = array_column($this->statusesFor((string) $task['list']['id']), 'status');
        $new = select("Status for \"{$task['name']}\"", $names, default: $task['status']['status']);

        return $new === $task['status']['status']
            ? [[], ['no change']]
            : [['status' => $new], ["status: {$task['status']['status']} → <options=bold>{$new}</>"]];
    }

    private function statusesFor(string $listId): array
    {
        return $this->statuses[$listId] ??= $this->clickup->list($listId)['statuses'] ?? [];
    }

    private function closedStatus(array $statuses): string
    {
        $closed = collect($statuses)->firstWhere('type', 'closed')
            ?? collect($statuses)->firstWhere('type', 'done')
            ?? throw new RuntimeException('This list has no closed status; use --status instead.');

        return $closed['status'];
    }

    private function hasChanges(): bool
    {
        return $this->option('done')
            || $this->option('assign') || $this->option('unassign')
            || collect(['status', 'priority', 'due', 'name', 'desc', 'comment'])
                ->contains(fn ($o) => $this->option($o) !== null);
    }
}
