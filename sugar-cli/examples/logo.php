<?php

declare(strict_types=1);

/**
 * Logo — render Unicode box-drawing text art, tinted or plain.
 *
 *   php examples/logo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Cli\Logo;

// The same capability story as Theme::detect(): a pipe, TERM=dumb or
// NO_COLOR gets the art uncoloured instead of 11 lines of escape bytes.
$profile = ColorProfile::detect(null, STDOUT);

$tint = static function (Logo $logo, string $hex) use ($profile): Logo {
    return $profile->supports256() ? $logo->withColor($hex) : $logo;
};

echo "\n";
echo "=== SugarCraft built-in logo ===\n\n";
echo $tint(Logo::sugarcraft(), '#ff5fd2')->render();
echo "\n\n";

echo "=== Plain text-art logo ===\n\n";
echo Logo::fromAscii(<<<'ART'
  ╭──────────────────────╮
  │   MY APPLICATION     │
  │   v1.0.0             │
  ╰──────────────────────╯
ART)->render();
echo "\n\n";

echo "=== Colored custom logo ===\n\n";
echo $tint(Logo::fromAscii(<<<'ART'
  ╭────────────────╮
  │   HELLO WORLD  │
  ╰────────────────╯
ART), '#5fafff')->render();
echo "\n";
