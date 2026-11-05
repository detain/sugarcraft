<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

/**
 * OSC 8 hyperlink anchor — a clickable terminal link.
 *
 * Mirrors charmbracelet/lipgloss's Hyperlink field and the OSC 8 spec.
 * URL and ID are validated at construction to prevent ANSI escape
 * injection into the OSC 8 wire format (C0/DEL controls and the C1 range —
 * raw bytes and their UTF-8 C2 xx encodings — are rejected; a semicolon is
 * rejected in the ID, which would shift the URL field).
 *
 * @throws \InvalidArgumentException on any rejected value.
 *
 * @readonly
 */
final class Hyperlink implements \JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly string $id = '',
    ) {
        if (preg_match('/[\x00-\x1f\x7f\x80-\x9f]|\xC2[\x80-\x9F]/', $url) === 1) {
            throw new \InvalidArgumentException('Hyperlink URL must not contain control characters');
        }
        if (preg_match('/[\x00-\x1f\x7f\x80-\x9f]|\xC2[\x80-\x9F]/', $id) === 1) {
            throw new \InvalidArgumentException('Hyperlink ID must not contain control characters');
        }
        if (str_contains($id, ';')) {
            // Spelled as the spec's `id=` attribute (M4), a bare ';' in the
            // id would still split the parameter and shift the URL field.
            throw new \InvalidArgumentException('Hyperlink ID must not contain semicolons');
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

    /**
     * Value equality: two hyperlinks are equal iff their url and id match.
     */
    public function equals(self $other): bool
    {
        return $this->url === $other->url && $this->id === $other->id;
    }

    /**
     * Null-safe value equality: two optional hyperlinks are the same link
     * state iff both are null, or both are non-null and url+id match.
     *
     * Canonicaliser for every OSC 8 open/close decision, so hyperlink
     * bytes never depend on object identity — and an ID-only change is
     * detected as a change (url-only comparison silently dropped it).
     */
    public static function valuesEqual(?self $a, ?self $b): bool
    {
        return $a === null ? $b === null : ($b !== null && $a->equals($b));
    }

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{url: string, id: string}
     */
    public function __serialize(): array
    {
        return ['url' => $this->url, 'id' => $this->id];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * Fail-closed: the constructor's control-byte and semicolon checks are
     * a security control on the OSC 8 wire, so they are re-applied here —
     * a forged payload cannot smuggle escape bytes or a field-shifting
     * semicolon past them.
     *
     * @param array{url?: string, id?: string} $data
     */
    public function __unserialize(array $data): void
    {
        $url = (string) ($data['url'] ?? '');
        $id = (string) ($data['id'] ?? '');
        if (preg_match('/[\x00-\x1f\x7f\x80-\x9f]|\xC2[\x80-\x9F]/', $url) === 1) {
            throw new \InvalidArgumentException('Hyperlink URL must not contain control characters');
        }
        if (preg_match('/[\x00-\x1f\x7f\x80-\x9f]|\xC2[\x80-\x9F]/', $id) === 1) {
            throw new \InvalidArgumentException('Hyperlink ID must not contain control characters');
        }
        if (str_contains($id, ';')) {
            throw new \InvalidArgumentException('Hyperlink ID must not contain semicolons');
        }
        $this->url = $url;
        $this->id = $id;
    }

    /**
     * JSON serialization support.
     *
     * @return array{url: string, id: string}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
