<?php

declare(strict_types=1);

/**
 * Custom theme — start from a stock theme and swap individual Style
 * slots with Theme::with(); no fifteen-style constructor re-listing.
 * Scalar decorations (headingPrefix / headingSuffix / headingCase)
 * stay constructor parameters — see the README slot reference.
 *
 *   $ php examples/custom-theme.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;

// Start from the dark stock theme, then override one slot at a time.
$custom = Theme::dark()
    ->with('heading1', Style::new()->bold()->foreground(Color::hex('#ff5f87'))->underline())
    ->with('code', Style::new()->foreground(Color::hex('#ffd700')));

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
