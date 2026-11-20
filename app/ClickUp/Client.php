<?php

namespace App\ClickUp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the ClickUp v2 REST API.
 *
 * @see https://developer.clickup.com/reference
 */
class Client
{
    private const BASE_URL = 'https://api.clickup.com/api/v2';

    public function __construct(private TokenStore $tokens) {}

    /** The authenticated user. */
    public function user(): array
    {
        return $this->get('/user')['user'];
    }

    /** Workspaces (called "teams" in the v2 API) the user belongs to. */
    public function workspaces(): array
    {
        return $this->get('/team')['teams'];
    }

    /**
     * Filtered tasks across a whole workspace, following pagination up to $maxPages
     * (100 tasks each). "complete" is false when that cap cut the results short.
     *
     * @param  array  $filters  ClickUp query params, e.g. ['assignees' => [123], 'statuses' => ['open']]
     * @return array{tasks: array, complete: bool}
     */
    public function workspaceTasks(string $workspaceId, array $filters = [], int $maxPages = 10): array
    {
        $tasks = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->get("/team/{$workspaceId}/task", [...$filters, 'page' => $page]);
            $tasks = [...$tasks, ...($response['tasks'] ?? [])];

            if (($response['last_page'] ?? true) || count($response['tasks'] ?? []) === 0) {
                return ['tasks' => $tasks, 'complete' => true];
            }
        }

        return ['tasks' => $tasks, 'complete' => false];
    }

    /**
     * A single task. Custom IDs like "UAT-20" need the workspace ID to resolve.
     */
    public function task(string $id, ?string $workspaceId = null): array
    {
        return $this->get("/task/{$id}", [
            ...$this->customIdParams($id, $workspaceId),
            'include_subtasks' => 'true',
            'include_markdown_description' => 'true',
        ]);
    }

    /** Most recent comments, newest first (ClickUp returns up to 25). */
    public function comments(string $taskId): array
    {
        return $this->get("/task/{$taskId}/comment")['comments'] ?? [];
    }

    public function updateTask(string $taskId, array $data): array
    {
        return $this->put("/task/{$taskId}", $data);
    }

    /**
     * Post a comment as ClickUp rich text.
     *
     * @param  list<array>  $blocks  from CommentMarkup::toBlocks()
     */
    public function addComment(string $taskId, array $blocks): array
    {
        return $this->post("/task/{$taskId}/comment", ['comment' => $blocks, 'notify_all' => false]);
    }

    /**
     * Replace a comment's text.
     *
     * @param  list<array>  $blocks  from CommentMarkup::toBlocks()
     */
    public function updateComment(string $commentId, array $blocks): array
    {
        return $this->put("/comment/{$commentId}", ['comment' => $blocks]);
    }

    public function createTask(string $listId, array $data): array
    {
        return $this->post("/list/{$listId}/task", $data);
    }

    /** A list, including its available statuses. */
    public function list(string $listId): array
    {
        return $this->get("/list/{$listId}");
    }

    /** People who can be assigned tasks in a list. */
    public function listMembers(string $listId): array
    {
        return $this->get("/list/{$listId}/member")['members'] ?? [];
    }

    public function spaces(string $workspaceId): array
    {
        return $this->get("/team/{$workspaceId}/space", ['archived' => 'false'])['spaces'] ?? [];
    }

    /** Folders in a space, each with its lists embedded. */
    public function folders(string $spaceId): array
    {
        return $this->get("/space/{$spaceId}/folder", ['archived' => 'false'])['folders'] ?? [];
    }

    /** Lists that live directly in a space (not inside a folder). */
    public function folderlessLists(string $spaceId): array
    {
        return $this->get("/space/{$spaceId}/list", ['archived' => 'false'])['lists'] ?? [];
    }

    /**
     * Folders and lists shared with you directly. This is how guests see a
     * workspace, since they can't browse its spaces.
     *
     * @return array{tasks: array, lists: array, folders: array}
     */
    public function sharedHierarchy(string $workspaceId): array
    {
        return $this->get("/team/{$workspaceId}/shared")['shared'] ?? ['tasks' => [], 'lists' => [], 'folders' => []];
    }

    /**
     * Save an attachment to $path. Attachment URLs carry their own single-use
     * signed token, so no API token is sent to the attachments host.
     */
    public function download(string $url, string $path): void
    {
        try {
            Http::timeout(120)->sink($path)->get($url)->throw();
        } catch (RequestException $e) {
            @unlink($path);

            throw new RuntimeException("Download failed ({$e->response->status()}). Try again: the link is single-use.", previous: $e);
        } catch (ConnectionException $e) {
            @unlink($path);

            throw new RuntimeException("Download failed: {$e->getMessage()}", previous: $e);
        }
    }

    public static function isCustomId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9]*-\d+$/', $id);
    }

    private function customIdParams(string $id, ?string $workspaceId): array
    {
        return self::isCustomId($id) && $workspaceId
            ? ['custom_task_ids' => 'true', 'team_id' => $workspaceId]
            : [];
    }

    public function get(string $path, array $query = []): array
    {
        // ClickUp expects repeated "key[]=value" params, not Guzzle's "key[0]=value".
        $qs = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($query));

        // No payload: an empty query array would make Guzzle drop the query string above.
        return $this->send('get', $qs === '' ? $path : "{$path}?{$qs}");
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('post', $path, $data);
    }

    public function put(string $path, array $data = []): array
    {
        return $this->send('put', $path, $data);
    }

    private function send(string $method, string $path, ?array $payload = null): array
    {
        try {
            $args = $payload === null ? [$path] : [$path, $payload];

            return $this->request()->{$method}(...$args)->throw()->json() ?? [];
        } catch (RequestException $e) {
            $body = $e->response->json();
            $message = $body['err'] ?? $e->response->reason();

            throw new RuntimeException(
                "ClickUp API error ({$e->response->status()}): {$message}", previous: $e
            );
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach ClickUp: {$e->getMessage()}", previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['Authorization' => $this->tokens->get()])
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof RequestException && $e->response->status() === 429, throw: false);
    }
}
