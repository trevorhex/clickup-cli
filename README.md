# cu: a ClickUp CLI

A small [Laravel Zero](https://laravel-zero.com) app for working with ClickUp from the terminal. Built for macOS: the token lives in the Keychain and `--open` uses `open`. Elsewhere, set `CLICKUP_TOKEN` and skip `--open`.

## Setup

1. In ClickUp, generate a personal token under **Settings → Apps → API Token** (it starts with `pk_`).
2. Store it in the macOS Keychain (you'll be prompted for it):

   ```bash
   security add-generic-password -a "$USER" -s clickup-cli -w
   ```

   To replace a saved token, add `-U` to that command. `CLICKUP_TOKEN` in the environment takes precedence, which is useful for CI.
3. `composer install`, then `php cu me` to verify and save your default workspace.
4. Optional: put `cu` on your PATH, e.g. `ln -s "$PWD/cu" ~/.local/bin/cu` (or `/opt/homebrew/bin`; `/usr/local/bin` may not exist on Apple Silicon)

Settings (workspace, default list) live in `~/.config/cu/config.json`, and a list cache lives next to them. The token is never written to disk by `cu`.

## Commands

| Command | Purpose |
|---|---|
| `cu me [--switch]` | Verify the token and pick a default workspace |
| `cu tasks [search]` | Your open tasks. `-s status` (repeatable), `-l list`, `-d today\|overdue\|week\|YYYY-MM-DD`, `--closed`, `--everyone`, `--sort=due\|priority\|status\|updated\|list`, `--limit`, `--json` |
| `cu show <id>` | Details, description, subtasks, checklists and the last 5 comments. `-c N` (up to 25), `--open`, `--json` |
| `cu update <id>...` | `-s status`, `--done`, `-p priority`, `-d due`, `--name`, `--desc`, `-a`/`-u` assignee (repeatable), `-c comment`. With one ID and no flags, prompts for a new status |
| `cu comment <id> [msg]` | Add a comment (prompts if there's no message) |
| `cu create [name]` | `-l list`, `-s`, `-p`, `-d`, `-a` (default: you), `--unassigned`, `--desc`, `--parent` (the subtask goes in the parent's list unless you pass `-l`), `--open` |
| `cu lists [search]` | Space › Folder › List tree, optionally only paths containing `search`. `--default` picks the list for `cu create`, `--refresh` skips the cache |

Note that `-c` means a comment count in `show` but comment text in `update`.

### Fuzzy input

Matching ignores case, spaces, dashes and underscores, and prefers an exact match, then a prefix, then a substring.

- **Statuses**: `-s prog` finds "in progress" and `-s todo` finds "to do".
  - In `cu create`/`cu update`, if several statuses match you're asked to pick (or shown the options with `--no-interaction`).
  - In `cu tasks`, every matching status is included. Closed tasks are only fetched with `--closed`, so `-s done` needs it too. If nothing matches, `cu tasks` lists the statuses it found.
- **Lists**: in `cu create -l`, a partial name or path (`-l "spa book"`), or an ID. If several match, you're asked to pick. In `cu tasks -l`, any list whose name contains the text is included.
- **Priority**: `urgent`, `high`, `normal`, `low`, any prefix of those (`u`, `h`…), `1`–`4`, or `none`
- **Dates** (`create`/`update -d`): `today`, `tomorrow`, `fri`, `next monday`, `+3d`, `+2w`, `2026-11-05`, or `none` to clear. `cu tasks -d` only takes `today`, `overdue`, `week` or `YYYY-MM-DD`. `week` and a date mean "due on or before", so they include overdue tasks.
- **Assignees**: `me`, part of a username or email, or a user ID. A partial name has to match exactly one person in the list.
- **IDs**: plain task IDs (`868hyw6e4`) or custom IDs (`UAT-20`)

### Guest access

If you're a guest in a workspace, ClickUp hides its spaces from you. `cu lists` falls back to the folders and lists shared with you and groups them under **Shared with me**. Every other command works the same way.

### Examples

```bash
cu tasks -d overdue --sort=priority
cu tasks booking -l reserv -s todo
cu show 868hyw6e4
cu update 868hyw6e4 868hyw6e2 --done -c "Verified on staging"
cu update 868hyw6e4 -s review -a heather -d fri
cu create "Gift card checkout" -l "spa book" -p high -d +3d
```

## Development

```bash
./vendor/bin/pest      # tests (HTTP is faked; in Feature tests, stray real requests fail the test)
./vendor/bin/pint      # code style
php cu app:build       # optional: single-file PHAR in builds/
```

| Path | What's there |
|---|---|
| `app/ClickUp/Client.php` | ClickUp v2 API wrapper (auth header, `key[]=` query encoding, pagination capped at 10 pages / ~1,000 tasks, one retry on 429) |
| `app/ClickUp/TokenStore.php` | Reads the token from `CLICKUP_TOKEN` or the Keychain |
| `app/ClickUp/Hierarchy.php` | Space › Folder › List index plus shared items, cached for an hour |
| `app/Commands/ClickUpCommand.php` | Base command: error handling and list/status/user lookups |
| `app/Support/Parse.php` | Priority, date and fuzzy-match parsing |
| `app/Support/Format.php` | Colored status, priority and due-date output |
