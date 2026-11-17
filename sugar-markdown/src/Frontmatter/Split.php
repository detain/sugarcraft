<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Frontmatter;

/**
 * The two halves of a document {@see \SugarCraft\Markdown\Frontmatter::split()}
 * found: the YAML between the fences and everything after the closing fence.
 *
 * The body is returned untrimmed. Callers that want prose trim it; callers
 * that splice the body back onto a document can use {@see $bodyOffset}.
 */
final class Split
{
    /**
     * @param string $meta       the YAML block between the fences, unparsed
     * @param string $body       everything after the closing fence
     * @param int    $bodyOffset byte offset of the body within the original document
     */
    public function __construct(
        public readonly string $meta,
        public readonly string $body,
        public readonly int $bodyOffset,
    ) {
    }
}
