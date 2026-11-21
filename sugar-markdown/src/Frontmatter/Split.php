<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Frontmatter;

/**
 * One document split at its `---` fence by {@see \SugarCraft\Markdown\Frontmatter::split()}.
 *
 * `meta` is the raw text between the two fences, without the line break
 * that precedes the closing fence; reading it (YAML, key/value, anything)
 * is the caller's job. `body` is the document after the closing fence and
 * `bodyOffset` is the byte offset in the original string where it starts.
 */
final class Split
{
    public function __construct(
        public readonly string $meta,
        public readonly string $body,
        public readonly int $bodyOffset,
    ) {
    }
}
