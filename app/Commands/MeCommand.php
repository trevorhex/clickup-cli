<?php

namespace App\Commands;

use App\ClickUp\Client;
use App\Support\UserConfig;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\select;

class MeCommand extends Command
{
    protected $signature = 'me {--switch : Choose a different default workspace}';

    protected $description = 'Verify your token and show your ClickUp user and workspace';

    public function handle(Client $clickup, UserConfig $config): int
    {
        try {
            $user = $clickup->user();
            $workspaces = $clickup->workspaces();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  <fg=green>✔</> Authenticated as <options=bold>{$user['username']}</> <fg=gray>({$user['email']})</>");
        $this->line("  <fg=gray>User ID:</> {$user['id']}");

        if ($workspaces === []) {
            $this->warn('  You are not a member of any workspace.');

            return self::SUCCESS;
        }

        $config->set('user_id', $user['id']);
        $current = $config->get('workspace_id');
        $ids = array_column($workspaces, 'id');

        if ($this->option('switch') || ! in_array($current, $ids, true)) {
            $current = count($workspaces) === 1
                ? $workspaces[0]['id']
                : select('Default workspace', array_column($workspaces, 'name', 'id'));

            $config->set('workspace_id', (string) $current);
        }

        $this->newLine();
        $this->table(
            ['', 'Workspace', 'ID'],
            array_map(fn ($w) => [
                $w['id'] == $current ? '<fg=green>●</>' : '',
                $w['name'],
                $w['id'],
            ], $workspaces),
        );

        $this->line("  <fg=gray>Settings saved to {$config->path()}</>");

        return self::SUCCESS;
    }
}
