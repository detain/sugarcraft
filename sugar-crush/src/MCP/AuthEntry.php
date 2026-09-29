<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Represents stored OAuth credentials for a single MCP server.
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
