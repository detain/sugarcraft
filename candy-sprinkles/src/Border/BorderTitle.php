<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Border;

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
    public function __construct(
        public string $text,
        public TitleAnchor $anchor,
        public string $separator = ' ',
        public bool $embedded = false,
        public ?string $open = null,
        public ?string $close = null,
    ) {}
}
