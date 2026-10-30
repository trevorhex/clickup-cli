<?php

namespace App\Commands;

use App\ClickUp\Client;
use RuntimeException;

use function Laravel\Prompts\textarea;

class CommentCommand extends ClickUpCommand
{
    protected $signature = 'comment
        {id : Task ID}
        {message? : Comment text in Markdown (omit to type it in an editor prompt)}
        {--f|file= : Read the comment text from a file}
        {--e|edit= : Replace an existing comment instead: its ID (see `cu show`), or "last" for your newest one}';

    protected $description = 'Add a comment to a task, or edit one';

    protected function perform(): int
    {
        $id = $this->argument('id');

        // The comment endpoints need the real task ID, so look up custom IDs first.
        if (Client::isCustomId($id)) {
            $id = $this->clickup->task($id, $this->config->get('workspace_id'))['id'];
        }

        $message = $this->message();

        if ($edit = $this->option('edit')) {
            $commentId = $edit === 'last' ? $this->lastOwnComment($id) : $edit;
            $this->clickup->updateComment($commentId, $message);
            $this->line("  <fg=green>✔</> Comment <fg=gray>{$commentId}</> updated on <fg=gray>{$this->argument('id')}</>");

            return self::SUCCESS;
        }

        $comment = $this->clickup->addComment($id, $message);
        $this->line("  <fg=green>✔</> Comment <fg=gray>{$comment['id']}</> added to <fg=gray>{$this->argument('id')}</>");

        return self::SUCCESS;
    }

    private function message(): string
    {
        if ($file = $this->option('file')) {
            $text = is_readable($file) ? file_get_contents($file) : throw new RuntimeException("Can't read {$file}.");
        }

        $text ??= $this->argument('message')
            ?? ($this->input->isInteractive() ? textarea('Comment', required: true) : null);

        return trim((string) $text) !== '' ? $text : throw new RuntimeException('No comment text given.');
    }

    private function lastOwnComment(string $taskId): string
    {
        $mine = collect($this->clickup->comments($taskId))
            ->first(fn ($c) => (int) ($c['user']['id'] ?? 0) === $this->userId());

        return (string) ($mine['id'] ?? throw new RuntimeException('You have no recent comments on this task.'));
    }
}
