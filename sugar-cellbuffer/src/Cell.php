<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer;

use SugarCraft\Core\Util\Width;

/**
 * A single terminal cell — the atom of buffer rendering.
 *
 * A cell holds: a displayed rune (or grapheme cluster), an optional
 * inline {@see Style}, an optional OSC 8 {@see Hyperlink}, and a
 * display-cell width (1 for normal, 2 for wide chars like CJK/emoji).
 *
 * When width is 2 the next adjacent cell in the buffer MUST be an
 * empty "continuation" cell (rune '', width 0). Buffer's mutating API
 * ({@see Buffer::withCellAt()}, fill(), withRegion()) creates and sweeps
 * that partner automatically; grids supplied through Buffer::fromGrid()
 * remain the caller's responsibility.
 *
 * CONTROL-BYTE CONTRACT (security-relevant): a Cell rune is stored and
 * emitted VERBATIM — {@see Buffer::toAnsi()} and the diff encoder write
 * $rune straight to the terminal wire with no escaping. Unlike
 * {@see Hyperlink}, which rejects C0/DEL at construction, Cell does NOT
 * validate its rune: a rune is expected to be a single DISPLAY grapheme,
 * and a raw C0 control (\x00-\x1f) or DEL (\x7f) written as a rune would
 * be injected verbatim into the output stream. THE CALLER is responsible
 * for stripping/replacing control bytes before building cells from
 * untrusted text — escape sequences belong to the parser, never a cell.
 * A hard reject was evaluated and deferred: multiple foundation
 * consumers (sugar-table run-length border fills, sugar-charts label
 * compositors) legitimately splat
 * pre-rendered strings char-by-char into runes and rely on the
 * pass-through behaviour, so validation has to live at those call sites.
 *
 * Cell is a readonly value object: rebuild via new rather than with*().
 *
 * Mirrors charmbracelet/x/cellbuf.Cell — one grid position carrying
 * runes, style, link and display width.
 *
 * @readonly
 */
final class Cell implements \JsonSerializable
{
    /**
     * @param string      $rune   Displayed grapheme(s) — empty string for continuation cells.
     *                            Stored verbatim and emitted unescaped; the caller must strip
     *                            C0/DEL control bytes from untrusted text (see class docblock).
     * @param Style|null $style  Per-cell style or null for defaults
     * @param Hyperlink|null $link OSC 8 hyperlink or null
     * @param int         $width  Columns the rune occupies (0 continuation,
     *                            1 normal, 2 wide). Widths above 2 are run-length
     *                            spans and carry the SPAN LAW: the rune must be
     *                            exactly $width display columns wide (sugar-table
     *                            border fills store the full-width run as the
     *                            lead's rune). A short rune behind a large
     *                            declared width under-paints every frame it
     *                            touches — such pairings are refused.
     *
     * @throws \InvalidArgumentException when $width is negative, or when a
     *         width > 2 span declares more columns than its rune occupies.
     */
    public function __construct(
        /**
         * @deprecated the accessors are the canonical read surface (AGENTS.md
         * bare-accessor house law); these stay published readonly for
         * byte-compat with existing consumers. Use {@see self::rune()}.
         */
        public readonly string $rune,
        /** @deprecated use {@see self::style()} — canonical read surface. */
        public readonly ?Style $style,
        /** @deprecated use {@see self::link()} — canonical read surface. */
        public readonly ?Hyperlink $link,
        /** @deprecated use {@see self::width()} — canonical read surface. */
        public readonly int $width,
    ) {
        // Negative widths desync every column computation (toAnsi, diff,
        // encoder cursor). Widths above 2 are the legal run-length idiom
        // (sugar-table border fills), so larger values must MEASURE UP —
        // the width-vs-rune desync class (a one-char rune painting a
        // four-column cell under-paints three columns of every frame,
        // silently) is refused at the type, once, where it is created.
        if ($width < 0) {
            throw new \InvalidArgumentException("Cell width must not be negative: {$width}");
        }
        if ($width > 2 && Width::of($rune) !== $width) {
            throw new \InvalidArgumentException(
                "Cell run-length span of width {$width} requires a rune exactly {$width} display columns wide, got " . Width::of($rune)
            );
        }
    }

    /**
     * Default factory — blank cell.
     */
    public static function new(
        string $rune = ' ',
        ?Style $style = null,
        ?Hyperlink $link = null,
        int $width = 1,
    ): self {
        return new self($rune, $style, $link, $width);
    }

    /**
     * Factory: a blank continuation cell for wide-char neighbours.
     * Continuation cells have empty rune and zero width.
     */
    public static function continuation(): self
    {
        return new self('', null, null, 0);
    }

    /**
     * Displayed grapheme(s), returned verbatim.
     *
     * The value is emitted unescaped by {@see Buffer::toAnsi()} / the diff
     * encoder, so callers rendering untrusted text must have stripped
     * C0/DEL control bytes before the cell was built (see class docblock).
     */
    public function rune(): string { return $this->rune; }

    /** Per-cell style (or null for default rendering). */
    public function style(): ?Style { return $this->style; }

    /** OSC 8 hyperlink anchor, or null. */
    public function link(): ?Hyperlink { return $this->link; }

    /**
     * Display width in terminal cells.
     *
     * @return int 0 (continuation), 1 (normal), 2 (wide/CJK/emoji), or a
     *              larger column count for run-length spans — spans of 3+
     *              must carry a rune of exactly that many columns (the
     *              span law enforced by the constructor; 0 or above).
     */
    public function width(): int { return $this->width; }

    /**
     * Structural equality: two cells are equal iff their rendered
     * representation (rune, style, link, width) is identical.
     *
     * Used by Buffer::diff() for the diff hot-path .
     */
    public function equals(Cell $other): bool
    {
        return $this->rune() === $other->rune()
            && Style::valuesEqual($this->style(), $other->style())
            && Hyperlink::valuesEqual($this->link(), $other->link())
            && $this->width() === $other->width();
    }

    /**
     * Serialization hook for caching/IPC use cases.
     *
     * @return array{rune: string, style: ?array, link: ?array, width: int}
     */
    public function __serialize(): array
    {
        return [
            'rune' => $this->rune,
            'style' => $this->style?->__serialize(),
            'link' => $this->link?->__serialize(),
            'width' => $this->width,
        ];
    }

    /**
     * Unserialization hook for caching/IPC use cases.
     *
     * @param array{rune: string, style: ?array, link: ?array, width: int} $data
     */
    public function __unserialize(array $data): void
    {
        $rune = self::requireKey($data, 'rune', 'Cell');
        $style = self::requireKey($data, 'style', 'Cell');
        $link = self::requireKey($data, 'link', 'Cell');
        $width = (int) self::requireKey($data, 'width', 'Cell');
        if ($width < 0) {
            throw new \InvalidArgumentException("Cell width must not be negative: {$width}");
        }
        if ($width > 2 && Width::of((string) $rune) !== $width) {
            // The span law is a constructor invariant, so it is re-applied
            // here: a forged payload cannot mint a desyncing run-length cell.
            throw new \InvalidArgumentException(
                "Cell run-length span of width {$width} requires a rune exactly {$width} display columns wide"
            );
        }

        $this->rune = $rune;
        if ($style !== null && !is_array($style)) {
            throw new \InvalidArgumentException('Cell style payload must be an array or null');
        }
        if ($link !== null && !is_array($link)) {
            throw new \InvalidArgumentException('Cell link payload must be an array or null');
        }
        $this->style = $style !== null ? new Style(
            self::requireKey($style, 'fg', 'Style'),
            self::requireKey($style, 'bg', 'Style'),
            self::requireKey($style, 'attrs', 'Style'),
            $style['fgSlot'] ?? null,
            $style['bgSlot'] ?? null,
            $style['ul'] ?? null,
            $style['ulSlot'] ?? null,
        ) : null;
        $this->link = $link !== null ? new Hyperlink(
            self::requireKey($link, 'url', 'Hyperlink'),
            self::requireKey($link, 'id', 'Hyperlink'),
        ) : null;
        $this->width = $width;
    }

    /**
     * Shape guard shared by the family's __unserialize hooks — a missing
     * key is a named refusal, not an undefined-key warning or a TypeError
     * from null into a readonly property.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \InvalidArgumentException naming the payload and the key
     */
    private static function requireKey(array $data, string $key, string $payload): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new \InvalidArgumentException("{$payload} payload missing key '{$key}'");
        }

        return $data[$key];
    }

    /**
     * JSON serialization support.
     *
     * @return array{rune: string, style: ?array, link: ?array, width: int}
     */
    public function jsonSerialize(): array
    {
        return $this->__serialize();
    }
}
