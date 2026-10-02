<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Represents stored OAuth credentials for a single MCP server.
 *
 * A null `expiresAt` means the token's lifetime is UNKNOWN (the server
 * omitted the optional `expires_in`), never "valid forever by design": it is
 * served as-is because there is no deadline to refresh ahead of.
 *
 * `registrationClientUri` is the RFC 7592 per-client management URL. It came
 * after the first nine keys, so a row written before it existed loads with
 * `''` (no update possible) and nothing else changes.
 */
final readonly class AuthEntry
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $registrationAccessToken,
        public string $accessToken,
        public string $refreshToken,
        public ?int $expiresAt,
        public array $scopes = [],
        public string $tokenUrl = '',
        public string $registrationUrl = '',
        public string $registrationClientUri = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            clientId: $data['clientId'] ?? '',
            clientSecret: $data['clientSecret'] ?? '',
            registrationAccessToken: $data['registrationAccessToken'] ?? '',
            accessToken: $data['accessToken'] ?? '',
            refreshToken: $data['refreshToken'] ?? '',
            expiresAt: isset($data['expiresAt']) ? (int) $data['expiresAt'] : null,
            scopes: $data['scopes'] ?? [],
            tokenUrl: $data['tokenUrl'] ?? '',
            registrationUrl: $data['registrationUrl'] ?? '',
            registrationClientUri: $data['registrationClientUri'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'registrationAccessToken' => $this->registrationAccessToken,
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'expiresAt' => $this->expiresAt,
            'scopes' => $this->scopes,
            'tokenUrl' => $this->tokenUrl,
            'registrationUrl' => $this->registrationUrl,
            'registrationClientUri' => $this->registrationClientUri,
        ];
    }

    /**
     * Check if the token is already expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }

    /**
     * Check if the token expires within the given number of seconds.
     */
    public function expiresWithin(int $seconds): bool
    {
        return $this->expiresAt !== null && $this->expiresAt - time() < $seconds;
    }
}
