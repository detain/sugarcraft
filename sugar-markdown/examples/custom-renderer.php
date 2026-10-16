<?php

declare(strict_types=1);

/**
 * Custom theme — reach into Theme's constructor to swap individual
 * Style slots, then feed the theme to a Renderer.
 *
 *   $ php examples/custom-renderer.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Sprinkles\Style;

// Start from the dark stock theme, then override one slot.
$base = Theme::dark();
$custom = new Theme(
    heading1: Style::new()->bold()->foreground(Color::hex('#ff5f87'))->underline(),
    heading2: $base->heading2,
    heading3: $base->heading3,
    heading4: $base->heading4,
    heading5: $base->heading5,
    heading6: $base->heading6,
    paragraph: $base->paragraph,
    bold: $base->bold,
    italic: $base->italic,
    code: Style::new()->foreground(Color::hex('#ffd700')),
    codeBlock: $base->codeBlock,
    link: $base->link,
    blockquote: $base->blockquote,
    listMarker: $base->listMarker,
    rule: $base->rule,
    headingPrefix: '✨ ',
    headingSuffix: ' ✨',
    headingCase: 'upper',
);

$markdown = <<<MD
# Custom theme demo

Heading 1 above is **bold** + pink + underlined.

`Inline code` is gold.

  - alpha
  - beta
  - gamma
MD;

echo (new Renderer($custom))->render($markdown);
echo "\n";
