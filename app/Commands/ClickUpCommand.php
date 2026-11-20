<?php

namespace App\Commands;

use App\ClickUp\Client;
use App\ClickUp\Hierarchy;
use App\Support\CommentMarkup;
use App\Support\Parse;
use App\Support\UserConfig;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\select;

/**
 * Base for commands that talk to ClickUp: shared setup, lookups, and error handling.
 */
abstract class ClickUpCommand extends Command
{
    protected Client $clickup;

    protected UserConfig $config;

    abstract protected function perform(): int;

    public function handle(Client $clickup, UserConfig $config): int
    {
        $this->clickup = $clickup;
        $this->config = $config;

        try {
            return $this->perform();
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function workspaceId(): string
    {
        return (string) ($this->config->get('workspace_id')
            ?? throw new RuntimeException('No default workspace yet. Run `cu me` first.'));
    }

    protected function userId(): int
    {
        return (int) ($this->config->get('user_id')
            ?? throw new RuntimeException('Unknown user. Run `cu me` first.'));
    }

    protected function hierarchy(): Hierarchy
    {
        return app(Hierarchy::class);
    }

    /**
     * A task's attachments (including ones added in comments), numbered from 1 in
     * the order `cu show` lists them.
     *
     * @return array<int, array>
     */
    protected function attachments(array $task): array
    {
        $live = array_values(array_filter($task['attachments'] ?? [], fn ($a) => ! ($a['deleted'] ?? false)));

        return $live === [] ? [] : array_combine(range(1, count($live)), $live);
    }

    /**
     * Resolve a list from an ID or partial name, prompting when several match.
     *
     * @return array{id: string, name: string, path: string}
     */
    protected function resolveList(string $query): array
    {
        $matches = $this->hierarchy()->search($query);

        // The list cache is up to an hour old. Look up IDs directly, and refresh for names.
        if ($matches->isEmpty() && ctype_digit($query)) {
            return $this->fetchList($query)
                ?? throw new RuntimeException("No list with ID {$query}. Try `cu lists`.");
        }

        if ($matches->isEmpty()) {
            $matches = $this->hierarchy()->search($query, refresh: true);
        }

        if ($matches->isEmpty()) {
            throw new RuntimeException("No list matches \"{$query}\". Try `cu lists`.");
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException(
                "\"{$query}\" matches several lists — be more specific or use the ID:\n  ".
                $matches->map(fn ($l) => "{$l['id']}  {$l['path']}")->implode("\n  ")
            );
        }

        $id = select('Which list?', $matches->pluck('path', 'id')->all(), scroll: 12);

        return $matches->firstWhere('id', (string) $id);
    }

    /**
     * A list that isn't in the cached hierarchy, such as a new one, or a guest's
     * list reached through a task shared on its own.
     *
     * @return array{id: string, name: string, path: string}|null
     */
    private function fetchList(string $id): ?array
    {
        try {
            $list = $this->clickup->list($id);
        } catch (RuntimeException) {
            return null;
        }

        $folder = ($list['folder']['hidden'] ?? true) ? null : ($list['folder']['name'] ?? null);

        return [
            'id' => (string) $list['id'],
            'name' => $list['name'],
            'path' => implode(' › ', array_filter([$folder, $list['name']])),
        ];
    }

    /**
     * Turn a comment's Markdown into ClickUp blocks, tagging each @mention that
     * matches one person who can see the task.
     *
     * @return list<array>
     */
    protected function commentBlocks(string $text, string $listId): array
    {
        $people = [];

        foreach (CommentMarkup::mentions($text) as $names) {
            // A full name as typed ("@Cameron Smith") wins, so it's tagged whole even if
            // several people share the first name.
            $members = $this->members($listId);
            $normalize = fn (string $s) => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
            foreach (array_slice($names, 0, -1) as $name) {
                $ids = array_keys(array_filter($this->memberNames[$listId], fn ($u) => $normalize($u) === $name));
                if (count($ids) === 1) {
                    $people[$name] = ['id' => (int) $ids[0], 'username' => $this->memberNames[$listId][$ids[0]]];

                    continue 2;
                }
            }

            $handle = end($names);
            $hits = Parse::fuzzy($handle, $members);

            if (count($hits) > 1) {
                throw new RuntimeException("@{$handle} matches several people: ".implode(', ', $hits).'. Use their full name or more of it, e.g. @firstlast.');
            }

            if ($hits === []) {
                $this->warn("  @{$handle} doesn't match anyone on this task's list, so it's left as plain text.");

                continue;
            }

            $id = (int) array_key_first($hits);
            $people[$handle] = ['id' => $id, 'username' => $this->memberNames[$listId][$id]];
        }

        return CommentMarkup::toBlocks($text, $people);
    }

    /** @var array<string, array<int, string>> list ID => user ID => username */
    private array $memberNames = [];

    /** @var array<string, array<int, string>> list ID => user ID => "username <email>" */
    private array $memberLabels = [];

    /**
     * People who can be assigned (and mentioned) in a list, fetched once.
     *
     * @return array<int, string> user ID => "username <email>"
     */
    protected function members(string $listId): array
    {
        if (! isset($this->memberLabels[$listId])) {
            $members = $this->clickup->listMembers($listId);
            $this->memberNames[$listId] = array_column($members, 'username', 'id');
            $this->memberLabels[$listId] = array_combine(
                array_column($members, 'id'),
                array_map(fn ($m) => "{$m['username']} <{$m['email']}>", $members),
            ) ?: [];
        }

        return $this->memberLabels[$listId];
    }

    /**
     * Match a status name against a list's statuses ("prog" → "in progress").
     */
    protected function resolveStatus(string $input, array $statuses): string
    {
        $names = array_column($statuses, 'status');
        $hits = Parse::fuzzy($input, array_combine($names, $names));

        if (count($hits) === 1) {
            return reset($hits);
        }

        if ($hits !== [] && $this->input->isInteractive()) {
            return select("Which status did you mean by \"{$input}\"?", array_values($hits));
        }

        throw new RuntimeException(
            ($hits === [] ? "No status matches \"{$input}\"." : "\"{$input}\" is ambiguous.").
            ' Available: '.implode(', ', $names)
        );
    }

    /**
     * Turn "me", usernames, or emails into user IDs using a list's members.
     *
     * @param  string[]  $people
     * @return int[]
     */
    protected function resolveUsers(array $people, string $listId): array
    {
        return array_map(function ($person) use ($listId) {
            if (in_array(strtolower($person), ['me', '@me'], true)) {
                return $this->userId();
            }

            if (ctype_digit($person)) {
                return (int) $person;
            }

            $hits = Parse::fuzzy($person, $this->members($listId));

            return match (count($hits)) {
                1 => (int) array_key_first($hits),
                0 => throw new RuntimeException("Nobody named \"{$person}\" can be assigned in this list."),
                default => throw new RuntimeException("\"{$person}\" matches several people: ".implode(', ', $hits)),
            };
        }, $people);
    }
}
