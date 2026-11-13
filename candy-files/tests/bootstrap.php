<?php

declare(strict_types=1);

// CF-35 determinism: the suite's goldens describe the unicode chrome
// tier, so pin it unless the developer explicitly exported
// CANDY_FILES_GLYPHS ($_SERVER-first, exactly how GlyphSet reads it —
// a real export still wins over the pin).
$_SERVER['CANDY_FILES_GLYPHS'] ??= (getenv('CANDY_FILES_GLYPHS') ?: 'unicode');

require __DIR__ . '/../vendor/autoload.php';
