<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Share;

use DateTimeImmutable;

/**
 * Result of a share operation containing the shareable URL and metadata.
 *
 * @mirrors charmbracelet/<repo>.ShareResult
 */
final readonly class ShareResult
{
    /**
     * @param string $url The short-lived signed URL for accessing the shared session.
     * @param DateTimeImmutable $expiresAt When the URL expires.
     * @param string $format The export format used (markdown, json, text).
     * @param int $messageCount Number of messages in the shared session.
     */
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt,
        public string $format,
        public int $messageCount,
    ) {}

    /**
     * Create a ShareResult from a ShareSession by serializing and uploading.
     *
     * @param ShareSession $session The session to share
     * @param string $format The export format (markdown, json, text)
     * @param int $expirySeconds Seconds until URL expires
     * @param string|null $uploadBaseUrl Base URL for upload (defaults to share.sugarcraft.dev)
     * @return self
     */
    public static function create(
        ShareSession $session,
        string $format,
        int $expirySeconds,
        ?string $uploadBaseUrl = null,
    ): self {
        $content = $session->serialize();
        $baseUrl = $uploadBaseUrl ?? 'https://share.sugarcraft.dev';

        $uploadResult = ShareUploader::upload($content, $format, $expirySeconds, $baseUrl);

        return new self(
            url: $uploadResult['url'],
            expiresAt: $uploadResult['expiresAt'],
            format: $format,
            messageCount: $session->messageCount(),
        );
    }

    /**
     * Check if the share URL has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    /**
     * Get remaining ttl in seconds.
     *
     * @return int Seconds until expiration, or 0 if already expired.
     */
    public function remainingTtl(): int
    {
        $now = new DateTimeImmutable();
        if ($this->isExpired()) {
            return 0;
        }

        return (int) ($this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Format for console display.
     *
     * Handles the edge case where seconds remain but round to 0 minutes.
     * For example, 3599s = 59m 59s -> shows "59m" (not "59m 0m" or "less than 1m").
     */
    public function toDisplayString(): string
    {
        $ttl = $this->remainingTtl();
        $hours = (int) floor($ttl / 3600);
        $minutes = (int) floor(($ttl % 3600) / 60);

        // Edge case: if minutes rounds to 0 but we have significant time, show seconds
        // Edge case: if hours > 0 and minutes rounds to 0, don't show "0m"
        $expiryStr = match (true) {
            $hours > 0 && $minutes > 0 => "{$hours}h {$minutes}m",
            $hours > 0 => "{$hours}h",
            $minutes > 0 => "{$minutes}m",
            $ttl > 0 => 'less than 1m',
            default => 'expired',
        };

        return <<<TEXT
Share URL: {$this->url}
Format: {$this->format}
Messages: {$this->messageCount}
Expires in: {$expiryStr}
TEXT;
    }
}
