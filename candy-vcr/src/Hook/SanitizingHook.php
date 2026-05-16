<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Hook;

use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Hook that sanitizes event payloads before they are written to the
 * cassette. Useful for removing sensitive data (API keys, tokens) or
 * normalizing content for sharing.
 *
 * Usage:
 * ```php
 * $recorder = Recorder::open('/tmp/session.cas');
 * $recorder->addHook(new SanitizingHook(
 *     removeKeys: ['API_KEY', 'AUTH_TOKEN'],
 *     replacePatterns: [
 *         '/password:\s*\S+/' => 'password: [REDACTED]',
 *     ],
 * ));
 * ```
 */
final class SanitizingHook implements Hook
{
    /** @var list<string> */
    private array $removeKeys;

    /** @var array<string, string> */
    private array $replacePatterns;

    /**
     * @param list<string> $removeKeys Payload keys to remove entirely
     * @param array<string, string> $replacePatterns Regex patterns => replacements for targeted sanitization
     */
    public function __construct(
        array $removeKeys = [],
        array $replacePatterns = [],
    ) {
        $this->removeKeys = $removeKeys;
        $this->replacePatterns = $replacePatterns;
    }

    public function beforeSave(Event $event): ?Event
    {
        $payload = $event->payload;

        // Remove specified keys (recursively)
        $payload = $this->removeKeysRecursive($payload, $this->removeKeys);

        // Apply regex replacements to string values
        foreach ($this->replacePatterns as $pattern => $replacement) {
            $payload = $this->applyPattern($payload, $pattern, $replacement);
        }

        // Return modified event (or same event if unchanged)
        if ($payload === $event->payload) {
            return $event;
        }

        return new Event($event->t, $event->kind, $payload);
    }

    public function afterCapture(Event $event): void
    {
        // No-op for sanitization hook
    }

    /**
     * Recursively apply regex replacement to payload values.
     *
     * @param array $data
     * @return array
     */
    private function applyPattern(array $data, string $pattern, string $replacement): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = preg_replace($pattern, $replacement, $value) ?? $value;
            } elseif (is_array($value)) {
                $data[$key] = $this->applyPattern($value, $pattern, $replacement);
            }
        }
        return $data;
    }

    /**
     * Recursively remove keys from payload.
     *
     * @param array $data
     * @param list<string> $keys
     * @return array
     */
    private function removeKeysRecursive(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->removeKeysRecursive($value, $keys);
            }
        }
        return $data;
    }
}
