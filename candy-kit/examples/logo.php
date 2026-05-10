<?php

declare(strict_types=1);

/**
 * Logo — render ASCII-art logos with color theming.
 *
 *   php examples/logo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Kit\Logo;

echo "\n";
echo "=== SugarCraft built-in logo (ANSI pink) ===\n\n";
echo Logo::sugarcraft()->withColor('#ff5fd2')->render();
echo "\n\n";

echo "=== Plain ASCII logo ===\n\n";
echo Logo::fromAscii(<<<'ART'
  ╭──────────────────────╮
  │   MY APPLICATION     │
  │   v1.0.0             │
  ╰──────────────────────╯
ART)->render();
echo "\n\n";

echo "=== Colored custom logo ===\n\n";
echo Logo::fromAscii(<<<'ART'
  ╭────────────────╮
  │   HELLO WORLD  │
  ╰────────────────╯
ART)->withColor('#5fafff')->render();
echo "\n";