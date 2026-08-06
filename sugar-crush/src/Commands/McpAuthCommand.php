<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\MCP\McpAuthStore;

/**
 * Implements the `mcp auth` command for managing MCP server OAuth credentials.
 *
 * Usage:
 *   mcp auth list                    — show all registered servers and their auth status
 *   mcp auth add <server>           — trigger OAuth registration for a server
 *   mcp auth remove <server>         — remove stored credentials for a server
 *
 * The command delegates token lifecycle (registration, fetch, refresh) to
 * {@see McpAuthStore} which wraps {@see \SugarCraft\Crush\MCP\OAuthClientRegistration}.
 *
 * @mirrors charmbracelet/<repo>.McpAuthCommand
 */
final class McpAuthCommand
{
    public function __construct(
        private readonly McpAuthStore $authStore,
    ) {
    }

    /**
     * Execute the `mcp auth` command.
     *
     * @param Chat  $chat  The current chat session (unused but part of the contract)
     * @param array $args  Parsed sub-command arguments: [subCommand, ...rest]
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat $chat, array $args = []): int
    {
        $subCommand = $args[0] ?? 'list';

        return match ($subCommand) {
            'list' => $this->listServers(),
            'add' => $this->addServer($args),
            'remove' => $this->removeServer($args),
            default => $this->printError("Unknown sub-command '{$subCommand}'. Use: list, add, remove"),
        };
    }

    /**
     * List all registered servers and their auth status.
     */
    private function listServers(): int
    {
        $servers = $this->authStore->listServers();

        if ($servers === []) {
            echo "\n";
            echo "  No MCP servers registered.\n";
            echo "\n";
            echo "  Run \033[33mmcp auth add <server>\033[0m to register a server.\n";
            echo "\n";

            return 0;
        }

        echo "\n";
        echo "  \033[1mMCP Servers\033[0m\n";
        echo "  " . str_repeat("─", 60) . "\n";

        foreach ($servers as $serverUrl => $status) {
            $statusStr = $this->formatStatus($status->statusLabel());
            $nameLen = strlen($serverUrl);
            $scopesStr = $status->scopes !== [] ? implode(', ', $status->scopes) : '—';

            echo "  {$serverUrl}";
            echo str_repeat(" ", max(1, 55 - $nameLen));
            echo "{$statusStr}\n";

            if ($status->expiresAt !== null) {
                $expireStr = date('Y-m-d H:i', $status->expiresAt);
                echo "    expires: {$expireStr}   scopes: {$scopesStr}\n";
            }
        }

        echo "\n";

        return 0;
    }

    /**
     * Trigger OAuth registration for a server.
     *
     * @param array<string> $args  [add, serverUrl, registrationUrl?, tokenUrl?]
     */
    private function addServer(array $args): int
    {
        $serverUrl = $args[1] ?? null;

        if ($serverUrl === null) {
            return $this->printError('Usage: mcp auth add <server> [registration-url] [token-url]');
        }

        $registrationUrl = $args[2] ?? null;
        $tokenUrl = $args[3] ?? null;

        // If registration URL not provided, try to discover from the server's well-known endpoint.
        // Many OAuth servers publish their metadata at /.well-known/oauth-authorization-server.
        if ($registrationUrl === null) {
            $wellKnown = rtrim($serverUrl, '/') . '/.well-known/oauth-authorization-server';
            try {
                $metadata = $this->fetchOAuthMetadata($wellKnown);
                $registrationUrl = $metadata['registration_endpoint'] ?? null;
                $tokenUrl = $metadata['token_endpoint'] ?? null;
            } catch (\Throwable) {
                // Discovery failed; require explicit URLs.
            }
        }

        if ($registrationUrl === null || $tokenUrl === null) {
            echo "\n";
            echo "  \033[33m!\033[0m OAuth endpoints could not be discovered for \033[36m{$serverUrl}\033[0m.\n";
            echo "\n";
            echo "  Please provide them explicitly:\n";
            echo "    \033[33mmcp auth add {$serverUrl} <registration-url> <token-url>\033[0m\n";
            echo "\n";

            return 1;
        }

        try {
            $clientName = 'sugar-crush/' . parse_url($serverUrl, PHP_URL_HOST);
            $oauth = $this->authStore->oauth();

            // Step 1: Register the client
            $registered = $oauth->registerClient($registrationUrl, $clientName);

            // Step 2: Fetch initial token
            $token = $oauth->fetchToken(
                $tokenUrl,
                $registered['clientId'],
                $registered['clientSecret'],
            );

            // Step 3: Save the auth entry
            $entry = new \SugarCraft\Crush\MCP\AuthEntry(
                clientId: $registered['clientId'],
                clientSecret: $registered['clientSecret'],
                registrationAccessToken: $registered['registrationAccessToken'],
                accessToken: $token['accessToken'],
                refreshToken: $token['refreshToken'],
                expiresAt: time() + $token['expiresIn'],
            );

            $oauth->saveAuth($serverUrl, $entry);

            echo "\n";
            echo "  \033[32m✓\033[0m Successfully registered \033[36m{$serverUrl}\033[0m\n";
            echo "  Client ID: \033[33m{$registered['clientId']}\033[0m\n";
            echo "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "\n";
            echo "  \033[31m✗\033[0m Registration failed: {$e->getMessage()}\n";
            echo "\n";

            return 1;
        }
    }

    /**
     * Remove stored credentials for a server.
     *
     * @param array<string> $args  [remove, serverUrl]
     */
    private function removeServer(array $args): int
    {
        $serverUrl = $args[1] ?? null;

        if ($serverUrl === null) {
            return $this->printError('Usage: mcp auth remove <server>');
        }

        if (!$this->authStore->hasServer($serverUrl)) {
            echo "\n";
            echo "  \033[33m!\033[0m No credentials found for \033[36m{$serverUrl}\033[0m.\n";
            echo "\n";

            return 1;
        }

        $this->authStore->removeServer($serverUrl);

        echo "\n";
        echo "  \033[32m✓\033[0m Removed credentials for \033[36m{$serverUrl}\033[0m\n";
        echo "\n";

        return 0;
    }

    /**
     * Fetch OAuth authorization server metadata from a well-known URL.
     *
     * @return array<string, mixed>
     */
    private function fetchOAuthMetadata(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error {$errno}: {$error}");
        }

        if ($body === '' || $body === false) {
            throw new \RuntimeException('Empty response from metadata URL');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Non-JSON metadata response');
        }

        return $data;
    }

    /**
     * Format a status label with ANSI color.
     */
    private function formatStatus(string $label): string
    {
        return match ($label) {
            'active' => "\033[32m● active\033[0m",
            'expired' => "\033[31m○ expired\033[0m",
            'expiring soon' => "\033[33m● expiring soon\033[0m",
            'no credentials' => "\033[90m○ no credentials\033[0m",
            default => "\033[90m{$label}\033[0m",
        };
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): int
    {
        echo "\n";
        echo "  \033[31m✗\033[0m {$message}\n";
        echo "\n";
        echo "  Usage:\n";
        echo "    mcp auth list                    — list registered servers\n";
        echo "    mcp auth add <server> [reg-url] [token-url]  — register a server\n";
        echo "    mcp auth remove <server>         — remove a server's credentials\n";
        echo "\n";

        return 1;
    }
}
