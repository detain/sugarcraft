<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * One result line of a search digest, already mapped to the three fields the
 * digest prints. Defaults are applied by each backend's mapper (a raw entry
 * with no title yields 'Untitled', one with no URL yields 'No URL'), so the
 * renderer never has to second-guess a remote payload's shape.
 *
 * The snippet arrives full-length; the caller that assembles the digest owns
 * the 200-character cut, the same rule for every backend.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $content,
    ) {
    }

    public static function new(string $title = 'Untitled', string $url = 'No URL', string $content = ''): self
    {
        return new self($title, $url, $content);
    }
}
