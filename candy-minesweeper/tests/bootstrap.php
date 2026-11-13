<?php

declare(strict_types=1);

// Suite bootstrap: autoload + colour hermeticity.
//
// The board's colours route through the fleet colour authority, which
// answers from the ambient environment (TERM / NO_COLOR / CI markers). The
// goldens and the colour-substring pins compare 24-bit SGR bytes, so the
// suite pins the capable answer ONCE here: every run sees identical bytes
// regardless of the runner's terminal, exactly like pinning the locale for
// translated-string asserts. NoColorTest flips the knob explicitly to prove
// the downgrade path.
require __DIR__ . '/../vendor/autoload.php';

\SugarCraft\Minesweeper\Renderer::setBoardColourOverride(true);

// Background hermeticity: the light-palette probe (msw-06) answers from the
// ambient COLORFGBG, and the goldens compare the dark bytes. Pin the dark
// answer here the same way the colour override is pinned; LightPaletteTest
// arms the light report explicitly to prove the twins.
putenv('COLORFGBG');
