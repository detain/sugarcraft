<?php

declare(strict_types=1);

/**
 * Styled text — the lipgloss "hello world".
 *
 *   php examples/styled-text.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

$banner = Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5f87'))
    ->background(Color::hex('#1a1a1a'))
    ->padding(1, 4)
    ->border(Border::rounded())
    ->borderForeground(Color::hex('#5fafff'));

echo $banner->render("Hello, candy world! 🍬") . "\n\n";

echo Style::new()
    ->italic()
    ->faint()
    ->render('A subtle subtitle.') . "\n";
