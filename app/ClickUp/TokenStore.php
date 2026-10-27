<?php

namespace App\ClickUp;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Resolves the ClickUp personal API token.
 *
 * Order: CLICKUP_TOKEN env var (handy for CI), then the macOS Keychain
 * item stored under the "clickup-cli" service name.
 */
class TokenStore
{
    public const KEYCHAIN_SERVICE = 'clickup-cli';

    private ?string $token = null;

    public function get(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $env = getenv('CLICKUP_TOKEN');
        if (is_string($env) && $env !== '') {
            return $this->token = trim($env);
        }

        $result = Process::run([
            'security', 'find-generic-password', '-s', self::KEYCHAIN_SERVICE, '-w',
        ]);

        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException(
                "No ClickUp token found. Store one with:\n\n".
                '  security add-generic-password -a "$USER" -s '.self::KEYCHAIN_SERVICE." -w\n"
            );
        }

        return $this->token = trim($result->output());
    }
}
