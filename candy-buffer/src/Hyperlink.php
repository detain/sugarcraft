<?php

declare(strict_types=1);

namespace SugarCraft\Buffer;

/**
 * OSC 8 hyperlink anchor — a clickable terminal link.
 *
 * Mirrors charmbracelet/lipgloss's Hyperlink field and the OSC 8 spec.
 * URL and ID are validated at construction to prevent ANSI escape
 * injection into the OSC 8 wire format (C0 controls rejected).
 *
 * @readonly
 */
final class Hyperlink
{
    public function __construct(
        public readonly string $url,
        public readonly string $id = '',
    ) {
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('Hyperlink URL must not contain control characters');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new \InvalidArgumentException('Hyperlink ID must not contain control characters');
        }
    }

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
