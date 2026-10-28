<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

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
 *
 * `redirectUri` is C-MCP-4.c: the EXACT loopback callback binding the grant
 * was registered and exchanged under (RFC 7591 asks the server to remember it
 * verbatim, so the client keeps a byte-for-byte copy of what it declared).
 * It came after the first ten keys: a row written before it existed — and
 * every `mcp auth add` row, which never had a callback to declare — loads
 * with `''`, meaning "no binding constrained this grant", not "the binding
 * is the empty string".
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
        public string $redirectUri = '',
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
            redirectUri: $data['redirectUri'] ?? '',
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
            'redirectUri' => $this->redirectUri,
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
