<?php

declare(strict_types=1);

namespace SugarCraft\Style\Border;

use SugarCraft\Core\Util\Width;

/**
 * A single title entry on a border.
 *
 * A plain title (ratatui `Block::title()`) is laid straight onto the edge.
 * An embedded title (`$embedded`, btop `createBox` law) is bracketed by
 * junction runes so the text reads as cut into the line: `─┐cpu┌─` on the
 * top edge, `─┘mem└─` on the bottom. `$open`/`$close` override those
 * junctions; null means "derive from the border's own corner family".
 *
 * @internal Not part of the public API — rendered by Style::applyBorder().
 */
final readonly class BorderTitle
{
    /**
     * Display width of `$text` (ANSI-aware) computed ONCE at title-attach
     * time — the same choke where the border validates rune widths — so
     * embed fitting skips a per-render re-measure (ST-35, finding 062).
     */
    public readonly int $textWidth;

    public function __construct(
        public string $text,
        public TitleAnchor $anchor,
        public string $separator = ' ',
        public bool $embedded = false,
        public ?string $open = null,
        public ?string $close = null,
    ) {
        $this->textWidth = Width::string($text);
    }
}
