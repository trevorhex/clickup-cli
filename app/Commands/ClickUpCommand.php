<?php

namespace App\Commands;

use App\ClickUp\Client;
use App\ClickUp\Hierarchy;
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
     * Resolve a list from an ID or partial name, prompting when several match.
     *
     * @return array{id: string, name: string, path: string}
     */
    protected function resolveList(string $query): array
    {
        $matches = $this->hierarchy()->search($query);

        if ($matches->isEmpty()) {
            throw new RuntimeException("No list matches \"{$query}\". Try `cu lists` (or `cu lists --refresh`).");
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
        $members = null;

        return array_map(function ($person) use (&$members, $listId) {
            if (in_array(strtolower($person), ['me', '@me'], true)) {
                return $this->userId();
            }

            if (ctype_digit($person)) {
                return (int) $person;
            }

            $members ??= collect($this->clickup->listMembers($listId))
                ->mapWithKeys(fn ($m) => [$m['id'] => "{$m['username']} <{$m['email']}>"])
                ->all();

            $hits = Parse::fuzzy($person, $members);

            return match (count($hits)) {
                1 => (int) array_key_first($hits),
                0 => throw new RuntimeException("Nobody named \"{$person}\" can be assigned in this list."),
                default => throw new RuntimeException("\"{$person}\" matches several people: ".implode(', ', $hits)),
            };
        }, $people);
    }
}
