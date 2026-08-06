<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Storage layer for MCP server OAuth credentials.
 *
 * Wraps {@see OAuthClientRegistration} to provide a simple key-value store
 * interface for server credentials. The actual token lifecycle (registration,
 * token fetch/refresh) is delegated to the OAuthClientRegistration instance;
 * this class only manages the mapping of server URLs to their auth entries.
 *
 * Auth data is persisted at ~/.local/share/sugar-crush/mcp-auth.json.
 *
 * @see OAuthClientRegistration
 */
final class McpAuthStore
{
    public function __construct(
        private readonly OAuthClientRegistration $oauth,
    ) {
    }

    /**
     * Create a McpAuthStore with a default OAuthClientRegistration.
     */
    public static function create(): self
    {
        return new self(new OAuthClientRegistration());
    }

    /**
     * List all registered servers and their auth status.
     *
     * @return array<string, ServerAuthStatus>
     */
    public function listServers(): array
    {
        $authData = $this->oauth->loadAuth();
        $result = [];

        foreach ($authData as $serverUrl => $entry) {
            $result[$serverUrl] = new ServerAuthStatus(
                serverUrl: $serverUrl,
                hasCredentials: true,
                isExpired: $entry->isExpired(),
                expiresAt: $entry->expiresAt,
                scopes: $entry->scopes,
            );
        }

        return $result;
    }

    /**
     * Check whether credentials exist for a given server.
     */
    public function hasServer(string $serverUrl): bool
    {
        $authData = $this->oauth->loadAuth();
        return isset($authData[$serverUrl]);
    }

    /**
     * Get the auth status for a specific server.
     */
    public function getServerStatus(string $serverUrl): ?ServerAuthStatus
    {
        $authData = $this->oauth->loadAuth();
        $entry = $authData[$serverUrl] ?? null;

        if ($entry === null) {
            return null;
        }

        return new ServerAuthStatus(
            serverUrl: $serverUrl,
            hasCredentials: true,
            isExpired: $entry->isExpired(),
            expiresAt: $entry->expiresAt,
            scopes: $entry->scopes,
        );
    }

    /**
     * Remove stored credentials for a given server.
     */
    public function removeServer(string $serverUrl): void
    {
        $this->oauth->deleteAuth($serverUrl);
    }

    /**
     * Get the underlying OAuthClientRegistration for token lifecycle operations.
     * Use this to trigger registration flows, token fetches, and refreshes.
     */
    public function oauth(): OAuthClientRegistration
    {
        return $this->oauth;
    }
}

/**
 * Represents the auth status of a single MCP server.
 */
final readonly class ServerAuthStatus
{
    public function __construct(
        public string $serverUrl,
        public bool $hasCredentials,
        public bool $isExpired,
        public ?int $expiresAt,
        public array $scopes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'serverUrl' => $this->serverUrl,
            'hasCredentials' => $this->hasCredentials,
            'isExpired' => $this->isExpired,
            'expiresAt' => $this->expiresAt,
            'scopes' => $this->scopes,
        ];
    }

    /**
     * Human-readable status string for CLI display.
     */
    public function statusLabel(): string
    {
        if (!$this->hasCredentials) {
            return 'no credentials';
        }

        if ($this->isExpired) {
            return 'expired';
        }

        if ($this->expiresAt !== null) {
            $remaining = $this->expiresAt - time();
            if ($remaining < 300) {
                return 'expiring soon';
            }
            return 'active';
        }

        return 'active';
    }
}
