# cu: a ClickUp CLI

A small [Laravel Zero](https://laravel-zero.com) app for working with ClickUp from the terminal.

## Setup

1. In ClickUp, generate a personal token under **Settings → Apps → API Token** (it starts with `pk_`).
2. Store it in the macOS Keychain (you'll be prompted for it):

   ```bash
   security add-generic-password -a "$USER" -s clickup-cli -w
   ```

   `CLICKUP_TOKEN` in the environment takes precedence, which is useful for CI.
3. `composer install`, then `php cu me` to verify and save your default workspace.
4. Optional: put `cu` on your PATH: `ln -s "$PWD/cu" /usr/local/bin/cu`

Settings (workspace, default list) live in `~/.config/cu/config.json`, and a list cache lives next to them. The token is never written to disk by `cu`.

## Commands

| Command | Purpose |
|---|---|
| `cu me [--switch]` | Verify the token and pick a default workspace |
| `cu tasks [search]` | Your tasks. `-s status` (repeatable), `-l list`, `-d today\|overdue\|week\|YYYY-MM-DD`, `--closed`, `--everyone`, `--sort=due\|priority\|status\|updated\|list`, `--limit`, `--json` |
| `cu show <id>` | Details, description, subtasks, checklists and the last 5 comments. `-c N`, `--open`, `--json` |
| `cu update <id>...` | `-s status`, `--done`, `-p priority`, `-d due`, `--name`, `--desc`, `-a`/`-u` assignee (repeatable), `-c comment`. With one ID and no flags, prompts for a new status |
| `cu comment <id> [msg]` | Add a comment (prompts if there's no message) |
| `cu create [name]` | `-l list`, `-s`, `-p`, `-d`, `-a` (default: you), `--unassigned`, `--desc`, `--parent`, `--open` |
| `cu lists [search]` | Space › Folder › List tree. `--default` picks the list for `cu create`, `--refresh` skips the cache |

### Fuzzy input

- **Statuses, lists, people**: partial, case-insensitive matches. `-s prog` finds "in progress" and `-s todo` finds "to do". If several match, you're asked to pick, or shown the options in `--no-interaction` mode.
- **Priority**: `urgent`/`u`/`1`, `high`, `normal`, `low`, `none`
- **Dates**: `today`, `tomorrow`, `fri`, `next monday`, `+3d`, `+2w`, `2026-11-05`, `none`
- **Assignees**: `me`, part of a username or email, or a user ID
- **IDs**: plain task IDs (`868hyw6e4`) or custom IDs (`UAT-20`)

### Guest access

If you're a guest in a workspace, ClickUp hides its spaces from you. `cu lists` falls back to the folders and lists shared with you and groups them under **Shared with me**. Every other command works the same way.

### Examples

```bash
cu tasks -d overdue --sort=priority
cu tasks booking -l reserv -s "to do"
cu show 868hyw6e4
cu update 868hyw6e4 868hyw6e2 --done -c "Verified on staging"
cu update 868hyw6e4 -s review -a heather -d fri
cu create "Gift card checkout" -l "spa book" -p high -d +3d
```

## Development

```bash
./vendor/bin/pest      # tests (HTTP is faked; stray real requests fail the test)
./vendor/bin/pint      # code style
php cu app:build       # optional: single-file PHAR in builds/
```

| Path | What's there |
|---|---|
| `app/ClickUp/Client.php` | ClickUp v2 API wrapper (auth header, `key[]=` query encoding, pagination, 429 retry) |
| `app/ClickUp/TokenStore.php` | Reads the token from `CLICKUP_TOKEN` or the Keychain |
| `app/ClickUp/Hierarchy.php` | Space › Folder › List index plus shared items, cached for an hour |
| `app/Commands/ClickUpCommand.php` | Base command: error handling and list/status/user lookups |
| `app/Support/Parse.php` | Priority, date and fuzzy-match parsing |
| `app/Support/Format.php` | Colored status, priority and due-date output |
