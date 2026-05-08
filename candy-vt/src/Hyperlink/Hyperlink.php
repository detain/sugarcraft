<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Hyperlink;

/**
 * OSC 8 hyperlink metadata attached to cells.
 *
 * Mirrors charmbracelet/x/vt Hyperlink.
 */
final readonly class Hyperlink
{
    public function __construct(
        public string $id = '',
        public string $uri = '',
    ) {}

    public static function fromRaw(string $id, string $uri): self
    {
        return new self($id, $uri);
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id && $this->uri === $other->uri;
    }
}
