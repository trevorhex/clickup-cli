<?php

namespace App\Commands;

use function Laravel\Prompts\search;

class ListsCommand extends ClickUpCommand
{
    protected $signature = 'lists
        {search? : Only lists whose name or path contains this text}
        {--default : Choose the default list for `cu create`}
        {--refresh : Re-fetch from ClickUp instead of using the 1-hour cache}';

    protected $description = 'Browse spaces, folders and lists, or set your default list';

    protected function perform(): int
    {
        $lists = $this->hierarchy()->lists($this->option('refresh'));

        if ($this->option('default')) {
            return $this->chooseDefault($lists);
        }

        if ($query = $this->argument('search')) {
            $lists = $lists->filter(fn ($l) => str_contains(mb_strtolower($l['path']), mb_strtolower($query)));
        }

        if ($lists->isEmpty()) {
            $this->info('  No lists found.');

            return self::SUCCESS;
        }

        $default = $this->config->get('default_list_id');

        // Print as a tree: Space, then Folder, then lists.
        $this->newLine();
        foreach ($lists->groupBy('space') as $space => $inSpace) {
            $this->line("  <options=bold>{$space}</>");

            foreach ($inSpace->groupBy(fn ($l) => $l['folder'] ?? '') as $folder => $inFolder) {
                $indent = $folder === '' ? '    ' : '      ';
                if ($folder !== '') {
                    $this->line("    <fg=cyan>{$folder}/</>");
                }

                foreach ($inFolder as $list) {
                    $marker = $list['id'] === $default ? ' <fg=green>● default</>' : '';
                    $this->line("{$indent}{$list['name']}  <fg=gray>{$list['id']}</>{$marker}");
                }
            }
            $this->newLine();
        }

        if (! $default) {
            $this->line('  <fg=gray>Tip: `cu lists --default` picks where `cu create` puts tasks.</>');
        }

        return self::SUCCESS;
    }

    private function chooseDefault($lists): int
    {
        $id = search(
            label: 'Default list for new tasks',
            options: fn (string $q) => $lists
                ->filter(fn ($l) => $q === '' || str_contains(mb_strtolower($l['path']), mb_strtolower($q)))
                ->pluck('path', 'id')
                ->all(),
            scroll: 12,
        );

        $list = $lists->firstWhere('id', (string) $id);
        $this->config->set('default_list_id', $list['id']);
        $this->info("  ✔ Default list set to {$list['path']}");

        return self::SUCCESS;
    }
}
