<?php

namespace App\Commands;

use App\ClickUp\Client;
use App\Support\Format;
use App\Support\Parse;
use App\Support\UserConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class TasksCommand extends Command
{
    protected $signature = 'tasks
        {search? : Only tasks whose name contains this text}
        {--s|status=* : Status to include (repeatable), e.g. -s "in progress" -s review}
        {--l|list= : List name (partial match) or list ID}
        {--d|due= : today, overdue, week, or a YYYY-MM-DD date (due on/before)}
        {--closed : Include closed tasks}
        {--everyone : Tasks for all assignees, not just you}
        {--sort=due : due, priority, status, updated, or list}
        {--limit=50 : Max rows to show}
        {--json : Output raw JSON}';

    protected $description = 'List tasks assigned to you';

    public function handle(Client $clickup, UserConfig $config): int
    {
        $workspaceId = $config->get('workspace_id');
        $userId = $config->get('user_id');

        if (! $workspaceId || ! $userId) {
            $this->error('No default workspace yet. Run `cu me` first.');

            return self::FAILURE;
        }

        try {
            $filters = $this->apiFilters($userId);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $tasks = collect($clickup->workspaceTasks($workspaceId, $filters));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tasks = $this->sort($this->localFilters($tasks));
        $total = $tasks->count();
        $tasks = $tasks->take((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line($tasks->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($tasks->isEmpty()) {
            $this->info('  No matching tasks. 🎉');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Task', 'Status', 'Pri', 'Due', 'List'],
            $tasks->map(fn ($t) => $this->row($t))->all(),
        );

        $shown = $tasks->count() < $total ? "{$tasks->count()} of {$total}" : $total;
        $this->line("  <fg=gray>{$shown} task(s). `cu show <id>` for details.</>");

        return self::SUCCESS;
    }

    /** Filters ClickUp can apply server-side. */
    private function apiFilters(int|string $userId): array
    {
        $filters = [
            'subtasks' => 'true',
            'include_closed' => $this->option('closed') ? 'true' : 'false',
        ];

        if (! $this->option('everyone')) {
            $filters['assignees'] = [$userId];
        }

        if ($statuses = $this->option('status')) {
            $filters['statuses'] = $statuses;
        }

        if (($list = $this->option('list')) && ctype_digit($list)) {
            $filters['list_ids'] = [$list];
        }

        if ($due = $this->option('due')) {
            [$after, $before] = $this->dueRange($due);
            if ($after) {
                $filters['due_date_gt'] = $after->getTimestampMs();
            }
            $filters['due_date_lt'] = $before->getTimestampMs();
        }

        return $filters;
    }

    /** @return array{0: ?CarbonImmutable, 1: CarbonImmutable} */
    private function dueRange(string $due): array
    {
        $now = CarbonImmutable::now();

        return match (strtolower($due)) {
            'overdue' => [null, $now],
            'today' => [$now->startOfDay()->subMillisecond(), $now->endOfDay()],
            'week' => [null, $now->addDays(7)->endOfDay()],
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)
                ? [null, CarbonImmutable::parse($due)->endOfDay()]
                : throw new RuntimeException("Unknown --due value \"{$due}\". Use today, overdue, week, or YYYY-MM-DD."),
        };
    }

    /** Filters that need matching ClickUp can't do (partial names). */
    private function localFilters(Collection $tasks): Collection
    {
        if ($search = $this->argument('search')) {
            $tasks = $tasks->filter(fn ($t) => Str::contains($t['name'], $search, ignoreCase: true));
        }

        if (($list = $this->option('list')) && ! ctype_digit($list)) {
            $tasks = $tasks->filter(fn ($t) => Str::contains($t['list']['name'] ?? '', $list, ignoreCase: true));
        }

        return $tasks->values();
    }

    private function sort(Collection $tasks): Collection
    {
        $key = match ($this->option('sort')) {
            'priority' => fn ($t) => Parse::PRIORITIES[$t['priority']['priority'] ?? ''] ?? 9,
            'status' => fn ($t) => [$t['status']['orderindex'] ?? 99, $t['status']['status']],
            'updated' => fn ($t) => -((int) $t['date_updated']),
            'list' => fn ($t) => $t['list']['name'] ?? '',
            // Due first, undated tasks last.
            default => fn ($t) => $t['due_date'] ? (int) $t['due_date'] : PHP_INT_MAX,
        };

        return $tasks->sortBy($key)->values();
    }

    private function row(array $t): array
    {
        return [
            '<fg=gray>'.Format::taskId($t).'</>',
            OutputFormatter::escape(Str::limit($t['name'], 55)),
            Format::status($t['status']),
            Format::priority($t['priority'] ?? null),
            Format::due($t['due_date'] ?? null),
            Str::limit($t['list']['name'] ?? '', 25),
        ];
    }
}
