<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use LogicException;
use RuntimeException;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Share\ShareResult;
use SugarCraft\Crush\Share\ShareSession;

/**
 * Implements the /share command that exports the current session to a signed URL.
 *
 * Accepts formats: markdown, json, plain text
 * Uses configurable upload base URL + sign with expiry
 * Returns a ShareResult with the URL, format, expiry.
 *
 * No real upload backend exists yet, so ShareUploader always fails —
 * this reports that honestly rather than fabricating a success URL.
 *
 * @mirrors charmbracelet/<repo>.ShareCommand
 */
final class ShareCommand
{
    private const DEFAULT_UPLOAD_BASE_URL = 'https://share.sugarcraft.dev';

    private const DEFAULT_EXPIRY_DAYS = 7;

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

        // Build ShareSession from chat history
        $session = new ShareSession($chat->history, $format, $expiry['days']);

        // Upload and get share result
        $uploadBaseUrl = $this->getUploadBaseUrl();

        try {
            ShareResult::create($session, $format, $expirySeconds, $uploadBaseUrl);
        } catch (RuntimeException $e) {
            $this->printNotImplemented($e->getMessage());
            return 1;
        }

        // Unreachable while no real upload backend exists: ShareUploader::upload()
        // is declared `never` and always throws, so ShareResult::create() can never
        // return here. Deliberately fail loudly instead of falling through to a
        // success message — there is no honest "shared successfully" output to
        // print until a real backend is wired up and this branch is rebuilt (and
        // re-audited) to match whatever that backend actually returns.
        throw new LogicException(
            'Unreachable: ShareResult::create() returned without throwing, but no '
            . '/share upload backend is configured to produce a real result.',
        );
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
     *
     * The canonical variable is SUGARCRUSH_SHARE_UPLOAD_URL.
     * SUGAR_CRUSH_SHARE_UPLOAD_URL is the original spelling and one of only two
     * app variables that ever carried the underscore after SUGAR — every other
     * SUGARCRUSH_* variable this app reads does not (crush_code.md Phase 4
     * item 4). It keeps working for one release so an operator who has pointed
     * /share at a private host does not silently start addressing the public
     * default the day the rename lands; the canonical name wins when both are
     * set, so the new export can be added to a shared profile before the old
     * one is removed.
     */
    private function getUploadBaseUrl(): string
    {
        foreach (['SUGARCRUSH_SHARE_UPLOAD_URL', 'SUGAR_CRUSH_SHARE_UPLOAD_URL'] as $name) {
            $envUrl = getenv($name);
            if (is_string($envUrl) && $envUrl !== '') {
                return $envUrl;
            }
        }

        return self::DEFAULT_UPLOAD_BASE_URL;
    }

    /**
     * Print the honest "no upload happened" failure.
     *
     * No real storage backend is configured for /share yet, so we report
     * that plainly instead of ever showing a fabricated URL or hash.
     */
    private function printNotImplemented(string $reason): void
    {
        echo "\n";
        echo "  ! `/share` is not yet implemented. No data was uploaded.\n";
        echo "  ({$reason})\n";
        echo "\n";
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): void
    {
        echo "\n";
        echo "  ✗ {$message}\n";
        echo "\n";
        echo "  Usage: /share [format] [expiry]\n";
        echo "\n";
        echo "  Formats: markdown (default), json, text\n";
        echo "  Expiry examples: 1h, 7d, 30m\n";
        echo "\n";
    }
}
