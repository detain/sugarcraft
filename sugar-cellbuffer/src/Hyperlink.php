<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

use SugarCraft\Core\Util\Ansi;

/**
 * OSC 8 hyperlink anchor — a clickable terminal link.
 *
 * Mirrors charmbracelet/lipgloss's Hyperlink field and the OSC 8 spec.
 * URL and ID are validated at construction to prevent ANSI escape
 * injection into the OSC 8 wire format: C0/DEL controls are refused, and so
 * are C1 escape introducers in both shapes that can reach the wire — lone raw
 * 0x80-0x9F bytes that do not continue a well-formed UTF-8 sequence, and
 * their C2 xx encodings (C2 80-C2 9F). Well-formed multibyte payloads (arrows,
 * emoji, Cyrillic — an IRI) pass, even though their continuation bytes fall
 * in the C1 window. A semicolon is rejected in the ID, which would shift the
 * URL field.
 *
 * @throws \InvalidArgumentException on any rejected value.
 *
 * @readonly
 */
final class Hyperlink implements \JsonSerializable
{
    /**
     * The OSC 8 close frame (empty params, empty URI): `ESC ] 8 ; ; ST`.
     * Single home for every close the fleet's emitters write, so the byte
     * shape can never drift between the repaint and delta paths.
     */
    public const CLOSE = "\x1b]8;;\x1b\\";

    public function __construct(
        public readonly string $url,
        public readonly string $id = '',
    ) {
        if (self::containsForbiddenControls($url)) {
            throw new \InvalidArgumentException('Hyperlink URL must not contain control characters');
        }
        if (self::containsForbiddenControls($id)) {
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
     * The OSC 8 open frame for this anchor, spelled per spec grammar
     * `OSC 8 ; params ; URI ST`: `ESC ] 8 ; <params> ; <url> ESC \`.
     *
     * With an id the params field carries the `id=<value>` attribute
     * (sugar-core's Ansi::hyperlinkOpen spelling); without one the params
     * field is EMPTY — the double semicolon stays, so the URI always sits
     * in its own field. Dropping it (`ESC ] 8 ; URI ST`) would slide the
     * URL into params and leave the URI field absent: conforming terminals
     * — and this fleet's own sugar-ansi-parser decoder, which reads the
     * two-field shape as a CLOSE — then never activate the link.
     */
    public function toAnsi(): string
    {
        $params = $this->id !== '' ? ('id=' . $this->id) : '';

        return "\x1b]8;{$params};{$this->url}\x1b\\";
    }

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
     * Refusal predicate: C0/DEL (leg 1), every UTF-8 C1 spelling C2 80-BF
     * (leg 1), and lone raw 8-bit C1 bytes (leg 2). Well-formed UTF-8
     * sequences whose continuation bytes happen to land in the 0x80-0x9F
     * numeric range (→, 😀, Cyrillic) are NOT controls — they must stay
     * constructible as IRI URLs. Leg 2 is sound because leg 1 already
     * refuses every ESC byte and every C2 xx spelling, so Ansi::strip()
     * can only differ on a LONE raw 0x80-0x9F byte. This matches the
     * sugar-core Sanitize::untrusted() strip-set exactly.
     */
    private static function containsForbiddenControls(string $value): bool
    {
        return preg_match('/[\x00-\x1f\x7f]|\xC2[\x80-\x9F]/', $value) === 1
            || Ansi::strip($value) !== $value;
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
        if (self::containsForbiddenControls($url)) {
            throw new \InvalidArgumentException('Hyperlink URL must not contain control characters');
        }
        if (self::containsForbiddenControls($id)) {
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
