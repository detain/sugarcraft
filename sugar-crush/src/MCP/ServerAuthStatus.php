<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

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
