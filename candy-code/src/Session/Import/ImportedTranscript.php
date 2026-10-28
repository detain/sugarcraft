<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

/**
 * The outcome of one {@see SessionImporter} call: which session the
 * transcript now lives in, and how much of it this call actually wrote.
 *
 * `items` counts the transcript rows the source carries (never the leading
 * untrusted-material notice, which is ours); `appended` counts the rows this
 * call added, so a byte-identical re-import reports zero and a grown source
 * reports its delta. `digest` is the sha256 of the raw input, which is the
 * identity the ledger compares against.
 */
final class ImportedTranscript
{
    private function __construct(
        public readonly ImportFormat $format,
        public readonly string $sessionId,
        public readonly ?string $title,
        public readonly int $items,
        public readonly int $appended,
        public readonly string $digest,
    ) {
    }

    public static function new(
        ImportFormat $format,
        string $sessionId,
        ?string $title,
        int $items,
        int $appended,
        string $digest,
    ): self {
        return new self($format, $sessionId, $title, $items, $appended, $digest);
    }
}
