<?php

namespace App\ClickUp;

use App\Support\Parse;
use App\Support\UserConfig;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Every list in the workspace (Space › Folder › List), cached on disk for an hour
 * since walking the tree takes a couple of API calls per space.
 */
class Hierarchy
{
    private const TTL_SECONDS = 3600;

    /** Stand-in "space" name for items shared with you outside a space you can see. */
    public const SHARED = 'Shared with me';

    public function __construct(private Client $clickup, private UserConfig $config) {}

    /**
     * @return Collection<int, array{id: string, name: string, space: string, folder: ?string, path: string}>
     */
    public function lists(bool $refresh = false): Collection
    {
        $workspaceId = (string) ($this->config->get('workspace_id')
            ?? throw new RuntimeException('No default workspace yet. Run `cu me` first.'));

        $cacheFile = "{$this->config->dir()}/lists-{$workspaceId}.json";

        if (! $refresh && is_file($cacheFile) && filemtime($cacheFile) > time() - self::TTL_SECONDS) {
            return collect(json_decode(file_get_contents($cacheFile), true) ?: []);
        }

        $lists = collect();

        foreach ($this->clickup->spaces($workspaceId) as $space) {
            foreach ($this->clickup->folderlessLists($space['id']) as $list) {
                $lists->push($this->entry($list, $space['name']));
            }

            foreach ($this->clickup->folders($space['id']) as $folder) {
                foreach ($folder['lists'] ?? [] as $list) {
                    $lists->push($this->entry($list, $space['name'], $folder['name']));
                }
            }
        }

        // Guests (and anyone with one-off shares) only see these.
        $shared = $this->clickup->sharedHierarchy($workspaceId);
        foreach ($shared['lists'] ?? [] as $list) {
            $lists->push($this->entry($list, self::SHARED));
        }
        foreach ($shared['folders'] ?? [] as $folder) {
            foreach ($folder['lists'] ?? [] as $list) {
                $lists->push($this->entry($list, self::SHARED, $folder['name']));
            }
        }

        $lists = $lists
            ->reject(fn ($l) => $l['archived'])
            ->unique('id')
            ->map(fn ($l) => collect($l)->except('archived')->all())
            ->values();

        // Don't cache an empty result; it's more likely a hiccup than reality.
        if ($lists->isNotEmpty()) {
            if (! is_dir($this->config->dir())) {
                mkdir($this->config->dir(), 0700, true);
            }
            file_put_contents($cacheFile, $lists->toJson());
        }

        return $lists;
    }

    /**
     * Lists matching an exact ID, or a partial name / path ("spa › book").
     */
    public function search(string $query): Collection
    {
        $lists = $this->lists();

        if ($exact = $lists->firstWhere('id', $query)) {
            return collect([$exact]);
        }

        $byName = Parse::fuzzy($query, $lists->pluck('name', 'id')->all())
            ?: Parse::fuzzy($query, $lists->pluck('path', 'id')->all());

        return $lists->whereIn('id', array_map('strval', array_keys($byName)))->values();
    }

    private function entry(array $list, string $space, ?string $folder = null): array
    {
        return [
            'id' => (string) $list['id'],
            'name' => $list['name'],
            'space' => $space,
            'folder' => $folder,
            'path' => implode(' › ', array_filter([$space, $folder, $list['name']])),
            'archived' => (bool) ($list['archived'] ?? false),
        ];
    }
}
