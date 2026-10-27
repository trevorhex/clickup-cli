<?php

namespace App\Commands;

use App\ClickUp\Client;
use RuntimeException;

use function Laravel\Prompts\textarea;

class CommentCommand extends ClickUpCommand
{
    protected $signature = 'comment
        {id : Task ID}
        {message? : Comment text (omit to type it in an editor prompt)}';

    protected $description = 'Add a comment to a task';

    protected function perform(): int
    {
        $id = $this->argument('id');

        // The comment endpoint needs the real task ID, so look up custom IDs first.
        if (Client::isCustomId($id)) {
            $id = $this->clickup->task($id, $this->config->get('workspace_id'))['id'];
        }

        $message = $this->argument('message')
            ?? ($this->input->isInteractive() ? textarea('Comment', required: true) : null)
            ?? throw new RuntimeException('No comment text given.');

        $this->clickup->addComment($id, $message);
        $this->line("  <fg=green>✔</> Comment added to <fg=gray>{$this->argument('id')}</>");

        return self::SUCCESS;
    }
}
