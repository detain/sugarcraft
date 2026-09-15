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
     *
     * @throws \InvalidArgumentException when a replace pattern is not a valid PCRE (E724)
     */
    public function __construct(
        array $removeKeys = [],
        array $replacePatterns = [],
    ) {
        // E724 (door probe — mirrors this lib's RegexAssertion ctor and
        // q2's filteredHostEnv fix): a mistyped pattern used to make every
        // preg_replace() below return NULL, the ?? fallback then handed the
        // ORIGINAL unsanitized value to the cassette — silently defeating
        // the only reason this hook exists (secret stripping). Patterns are
        // developer config, so the compile check belongs here, once, not
        // per payload value. The probe runs the REAL pattern/replacement
        // pair against an empty subject: a pattern that compiles cannot hit
        // a runtime limit on '', so NULL here means exactly "bad pair".
        foreach ($replacePatterns as $pattern => $replacement) {
            if (@preg_replace((string) $pattern, (string) $replacement, '') === null) {
                throw new \InvalidArgumentException(
                    "candy-vcr: sanitize pattern is not a valid PCRE pattern: {$pattern}",
                );
            }
        }

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
                // E724 truth + keep: preg_replace() returns NULL on ANY PCRE
                // error — never FALSE (that sentinel belongs to preg_match();
                // the stub type is array|string|null) — so ?? is the
                // complete guard and no value can be corrupted with a
                // non-string. Bad-pattern NULLs no longer reach here (the
                // ctor probe throws), leaving only pcre.backtrack/recursion
                // limit exhaustion on a hostile payload; there the old value
                // survives rather than NULL being written into the payload
                // or the whole recording aborting mid-event.
                // @ kept: the null result IS the handler for that warning —
                // letting it print would double-report outside our channel.
                $data[$key] = @preg_replace($pattern, $replacement, $value) ?? $value;
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
