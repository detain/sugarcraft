<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * The importer core behind `crush import` (C-SES-7.b) and its RPC sibling
 * (C-SES-7.e): recognise a foreign (or own-format) transcript, flatten it
 * into Messages, and persist it as a NEW main session whose provider is
 * `import:<source>`, so the FTS index rides the incremental sync of
 * {@see EnhancedSessionStore::saveTranscript()} and an imported session is
 * body-searchable the moment it lands — no rebuild, anywhere.
 *
 * The four shapes {@see ImportFormat} names are auto-detected by their
 * leading structure; each reader refuses anything that deviates from its
 * version-pinned schema rather than guessing at a drifted producer.
 *
 * Safety laws this class owns:
 *  - BOUNDED. At most 50k transcript items and 64 MiB of raw input by
 *    default (the OpenClaw bounds); beyond either the import is refused
 *    with a typed {@see ImportRefusedException}, never truncated.
 *  - REDACTED. Every text passes through {@see SecretRedactor} inside the
 *    readers, so someone else's API key cannot take up residence in this
 *    store.
 *  - INERT. Foreign tool calls and results arrive as flattened text rows;
 *    only candy-code's own export may round-trip real tool rows.
 *  - UNTRUSTED. The session's first row is a UI-only notice naming the
 *    import, so the transcript is marked reference material in the very
 *    frame the user sees, and no foreign row can impersonate the operator.
 *  - UNPRICED. No usage rows are written: the session shows `≥` for spend,
 *    exactly as an unmetered one should.
 *
 * Re-import of the same source appends only the delta, remembered in a
 * {@see SessionImportLedger} row keyed by the source path (or the caller's
 * own key for string imports).
 */
final class SessionImporter
{
    /** Transcript rows one import may carry (the OpenClaw item bound). */
    public const MAX_ITEMS = 50000;

    /** Raw bytes one import may read (64 MiB, the OpenClaw size bound). */
    public const MAX_BYTES = 67_108_864;

    private function __construct(
        public readonly int $maxItems,
        public readonly int $maxBytes,
    ) {
    }

    public static function new(?int $maxItems = null, ?int $maxBytes = null): self
    {
        return new self($maxItems ?? self::MAX_ITEMS, $maxBytes ?? self::MAX_BYTES);
    }

    /** An importer with different bounds; the receiver is untouched. */
    public function withBounds(?int $maxItems = null, ?int $maxBytes = null): self
    {
        return new self($maxItems ?? $this->maxItems, $maxBytes ?? $this->maxBytes);
    }

    /**
     * Import the transcript file at $path — a path the person at the
     * terminal named, read exactly once, into $store.
     *
     * @throws ImportRefusedException when the file, its size, or its shape
     *                                cannot be imported
     */
    public function importFile(
        EnhancedSessionStore $store,
        string $path,
        ?ImportFormat $format = null,
        ?string $name = null,
    ): ImportedTranscript {
        if (!is_file($path)) {
            throw ImportRefusedException::notReadable($path);
        }
        $size = filesize($path);
        if ($size !== false && $size > $this->maxBytes) {
            throw ImportRefusedException::tooLarge($size, $this->maxBytes);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ImportRefusedException::notReadable($path);
        }

        $key = realpath($path) ?: $path;

        return $this->importString($store, $raw, $format, $name, $key);
    }

    /**
     * Import an in-memory transcript. $sourceKey is the ledger identity a
     * re-import is compared against; for files it is the resolved path.
     *
     * @throws ImportRefusedException when the bytes, shape, or size of the
     *                                transcript cannot be imported
     */
    public function importString(
        EnhancedSessionStore $store,
        string $raw,
        ?ImportFormat $format = null,
        ?string $name = null,
        string $sourceKey = 'string:import',
    ): ImportedTranscript {
        if (strlen($raw) > $this->maxBytes) {
            throw ImportRefusedException::tooLarge(strlen($raw), $this->maxBytes);
        }

        $format ??= self::detect($raw);
        $redactor = SecretRedactor::new();
        $rows = self::readRows($format, $raw, $redactor, $this->maxItems);
        $messages = $rows['messages'];
        $title = $rows['title'];
        if ($messages === []) {
            throw ImportRefusedException::shape('the transcript carries no conversation rows');
        }

        return $this->persist($store, $format, $messages, $title, $name, $sourceKey, $raw);
    }

    /**
     * @param list<Message> $messages
     */
    private function persist(
        EnhancedSessionStore $store,
        ImportFormat $format,
        array $messages,
        ?string $title,
        ?string $name,
        string $sourceKey,
        string $raw,
    ): ImportedTranscript {
        $digest = hash('sha256', $raw);
        $ledger = SessionImportLedger::atPath($store->path());
        $stored = $ledger->lookup($sourceKey);
        $label = $name ?? $title;

        if ($stored !== null) {
            $current = $store->loadTranscript($stored['session_id']);
            if ($current === null) {
                // The imported session was deleted since. The ledger only
                // remembers lives, not graves: a fresh import mints a fresh
                // session and rewrites the row.
            } elseif ($stored['digest'] === $digest) {
                return ImportedTranscript::new(
                    $format,
                    $stored['session_id'],
                    $label,
                    $stored['items'],
                    0,
                    $digest,
                );
            } else {
                return $this->appendTo(
                    $store,
                    $ledger,
                    $format,
                    $messages,
                    $label,
                    $sourceKey,
                    $stored['session_id'],
                    $stored['items'],
                    $digest,
                );
            }
        }

        $sessionId = bin2hex(random_bytes(16));
        $store->createSession($sessionId, 'import:' . $format->value, 'unknown', null, $label);
        $notice = Message::notice(Lang::t('session.import.notice', ['format' => $format->label()]));
        $store->saveTranscript($sessionId, [$notice, ...$messages]);
        $ledger->remember($sourceKey, $sessionId, count($messages), $digest);

        return ImportedTranscript::new($format, $sessionId, $label, count($messages), count($messages), $digest);
    }

    /**
     * A re-import of a changed source: append only the rows the ledger has
     * not seen. The stored item count is a prefix offset, which is honest
     * while a transcript only grows the way conversation logs do; a source
     * that shrank below the stored prefix is refused rather than rewritten.
     *
     * @param list<Message> $messages
     */
    private function appendTo(
        EnhancedSessionStore $store,
        SessionImportLedger $ledger,
        ImportFormat $format,
        array $messages,
        ?string $label,
        string $sourceKey,
        string $sessionId,
        int $storedItems,
        string $digest,
    ): ImportedTranscript {
        if (count($messages) < $storedItems) {
            throw ImportRefusedException::sourceShrank();
        }
        $existing = $store->loadTranscript($sessionId) ?? [];
        $delta = \array_slice($messages, $storedItems);
        if ($delta !== []) {
            $store->saveTranscript($sessionId, [...$existing, ...$delta]);
        }
        $ledger->remember($sourceKey, $sessionId, count($messages), $digest);

        return ImportedTranscript::new($format, $sessionId, $label, count($messages), count($delta), $digest);
    }

    /**
     * @return array{messages: list<Message>, title: ?string}
     */
    private static function readRows(ImportFormat $format, string $raw, SecretRedactor $redactor, int $maxItems): array
    {
        return match ($format) {
            ImportFormat::CandyCode => CandyCodeExportReader::read($raw, $redactor, $maxItems),
            ImportFormat::ClaudeCode => ClaudeCodeTranscriptReader::read($raw, $redactor, $maxItems),
            ImportFormat::Codex => CodexTranscriptReader::read($raw, $redactor, $maxItems),
            ImportFormat::OpenCode => OpenCodeExportReader::read($raw, $redactor, $maxItems),
        };
    }

    /**
     * Recognise the format from the shape of the bytes. Leading structure
     * picks the candidate; the reader's refusal is the final word, so this
     * never has to be clever about a body it will not parse anyway.
     */
    private static function detect(string $raw): ImportFormat
    {
        $trim = ltrim($raw);
        if ($trim === '') {
            throw ImportRefusedException::unknownFormat();
        }

        if (str_starts_with($trim, '[')) {
            return ImportFormat::CandyCode;
        }

        if (str_starts_with($trim, '{')) {
            // A whole-file object that carries a messages list is an OpenCode
            // export; a line-delimited object is a JSONL log.
            try {
                $decoded = json_decode($trim, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            if (\is_array($decoded) && isset($decoded['messages'])) {
                return ImportFormat::OpenCode;
            }

            return self::detectJsonl($trim);
        }

        throw ImportRefusedException::unknownFormat();
    }

    /**
     * The first line that decodes to a typed object names the JSONL family:
     * Codex's rollout envelope, or a Claude Code conversation (whose leading
     * lines may be timestamp-less telemetry, skipped as they are here).
     */
    private static function detectJsonl(string $raw): ImportFormat
    {
        foreach (explode("\n", $raw) as $text) {
            if (trim($text) === '') {
                continue;
            }
            try {
                $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                break;
            }
            if (!\is_array($decoded) || !\is_string($decoded['type'] ?? null)) {
                break;
            }
            $type = $decoded['type'];
            if (\in_array($type, ['session_meta', 'response_item', 'turn_context', 'event_msg'], true)) {
                return ImportFormat::Codex;
            }
            if (\in_array($type, ['user', 'assistant', 'system'], true)) {
                return ImportFormat::ClaudeCode;
            }
            if (\in_array($type, ClaudeCodeTranscriptReader::TELEMETRY_TYPES, true)) {
                continue;
            }
            break;
        }

        throw ImportRefusedException::unknownFormat();
    }
}
