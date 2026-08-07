<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use DateTimeImmutable;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Share\ShareResult;
use SugarCraft\Crush\Share\ShareSession;
use SugarCraft\Crush\Share\ShareUploader;

/**
 * Implements the /share command that exports the current session to a signed URL.
 *
 * Accepts formats: markdown, json, plain text
 * Uses configurable upload base URL + sign with expiry
 * Returns a ShareResult with the URL, format, expiry.
 *
 * @mirrors charmbracelet/<repo>.ShareCommand
 */
final class ShareCommand
{
    private const DEFAULT_UPLOAD_BASE_URL = 'https://share.sugarcraft.dev';

    private const DEFAULT_EXPIRY_DAYS = 7;

    /** Default expiry in hours (for short-lived shares). */
    private const DEFAULT_EXPIRY_HOURS = 1;

    /**
     * @param list<string> $args Command arguments: [format, expiry]
     */
    public function execute(Chat $chat, array $args = []): int
    {
        // Parse format
        $format = $this->parseFormat($args[0] ?? '');
        if ($format === null) {
            $this->printError("Invalid format '{$args[0]}'. Supported: markdown, json, text");
            return 1;
        }

        // Parse expiry
        $expiry = $this->parseExpiry($args[1] ?? '');
        $expirySeconds = $expiry['seconds'];
        $expiryDisplay = $expiry['display'];

        // Build ShareSession from chat history
        $messages = array_map(
            static fn(Message $m): Message => $m,
            $chat->history,
        );

        $session = new ShareSession($messages, $format, $expiry['days']);

        // Upload and get share result
        $uploadBaseUrl = $this->getUploadBaseUrl();
        $result = ShareResult::create($session, $format, $expirySeconds, $uploadBaseUrl);

        $this->printResult($result, $expiryDisplay);

        return 0;
    }

    /**
     * Parse format argument.
     */
    private function parseFormat(string $arg): ?string
    {
        if ($arg === '') {
            return ShareSession::FORMAT_MARKDOWN;
        }

        return match (strtolower($arg)) {
            'markdown', 'md' => ShareSession::FORMAT_MARKDOWN,
            'json' => ShareSession::FORMAT_JSON,
            'text', 'plain' => ShareSession::FORMAT_TEXT,
            default => null,
        };
    }

    /**
     * Parse expiry argument.
     *
     * @return array{seconds:int,days:int,display:string}
     */
    private function parseExpiry(string $arg): array
    {
        if ($arg === '') {
            $days = self::DEFAULT_EXPIRY_DAYS;
            return [
                'seconds' => $days * 86400,
                'days' => $days,
                'display' => "{$days} days",
            ];
        }

        // Parse formats like "1h", "7d", "30m", "3600" (seconds)
        if (preg_match('/^(\d+)([hmd])$/', strtolower($arg), $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'h' => [
                    'seconds' => $value * 3600,
                    'days' => (int) ceil($value / 24),
                    'display' => "{$value} hour" . ($value !== 1 ? 's' : ''),
                ],
                'd' => [
                    'seconds' => $value * 86400,
                    'days' => $value,
                    'display' => "{$value} day" . ($value !== 1 ? 's' : ''),
                ],
                'm' => [
                    'seconds' => $value * 60,
                    'days' => 0,
                    'display' => "{$value} minute" . ($value !== 1 ? 's' : ''),
                ],
                default => [
                    'seconds' => self::DEFAULT_EXPIRY_DAYS * 86400,
                    'days' => self::DEFAULT_EXPIRY_DAYS,
                    'display' => self::DEFAULT_EXPIRY_DAYS . ' days',
                ],
            };
        }

        // Default
        $days = self::DEFAULT_EXPIRY_DAYS;
        return [
            'seconds' => $days * 86400,
            'days' => $days,
            'display' => "{$days} days",
        ];
    }

    /**
     * Get configured upload base URL or default.
     */
    private function getUploadBaseUrl(): string
    {
        $envUrl = getenv('SUGAR_CRUSH_SHARE_UPLOAD_URL');
        if ($envUrl !== false && $envUrl !== '') {
            return $envUrl;
        }

        return self::DEFAULT_UPLOAD_BASE_URL;
    }

    /**
     * Print the share result.
     */
    private function printResult(ShareResult $result, string $expiryDisplay): void
    {
        echo "\n";
        echo "  \033[32m✓\033[0m Session shared successfully\n";
        echo "\n";
        echo "  URL: {$result->url}\n";
        echo "  Format: {$result->format}\n";
        echo "  Messages: {$result->messageCount}\n";
        echo "  Expires: {$expiryDisplay}\n";
        echo "\n";
        echo "  Share URL expires at: " . $result->expiresAt->format('Y-m-d H:i:s T') . "\n";
        echo "\n";
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "  \033[31m✗\033[0m {$message}\n";
        echo "\n";
        echo "  Usage: /share [format] [expiry]\n";
        echo "\n";
        echo "  Formats: markdown (default), json, text\n";
        echo "  Expiry examples: 1h, 7d, 30m\n";
        echo "\n";
    }
}
