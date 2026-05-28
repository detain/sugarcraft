<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * OSC 8 hyperlink anchor — a clickable terminal link.
 *
 * Mirrors charmbracelet/lipgloss's Hyperlink field and the OSC 8 spec.
 *
 * @readonly
 */
final class Hyperlink
{
    public function __construct(
        public readonly string $url,
        public readonly string $id = '',
    ) {}

    /**
     * Factory matching upstream: Hyperlink(url, id).
     */
    public static function new(string $url, string $id = ''): self
    {
        return new self($url, $id);
    }

    public function url(): string  { return $this->url; }
    public function id(): string   { return $this->id; }
}
