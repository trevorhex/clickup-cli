<?php

namespace App\Support;

/**
 * Small JSON settings file at ~/.config/cu/config.json
 * (default workspace, default list, etc.). Never holds the token.
 */
class UserConfig
{
    private array $data;

    public function __construct(private ?string $path = null)
    {
        $this->path ??= ($_SERVER['HOME'] ?? getenv('HOME')).'/.config/cu/config.json';
        $this->data = is_file($this->path)
            ? (json_decode(file_get_contents($this->path), true) ?: [])
            : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0700, true);
        }

        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Directory holding config and cache files. */
    public function dir(): string
    {
        return dirname($this->path);
    }
}
