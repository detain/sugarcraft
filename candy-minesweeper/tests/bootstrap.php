<?php

declare(strict_types=1);

// Suite bootstrap: autoload + colour hermeticity.
//
// M12 routed the board's colours through the fleet colour authority, which
// answers from the ambient environment (TERM / NO_COLOR / CI markers). The
// goldens and the colour-substring pins compare 24-bit SGR bytes, so the
// suite pins the capable answer ONCE here: every run sees identical bytes
// regardless of the runner's terminal, exactly like pinning the locale for
// translated-string asserts. NoColorTest flips the knob explicitly to prove
// the downgrade path.
require __DIR__ . '/../vendor/autoload.php';

\SugarCraft\Minesweeper\Renderer::setBoardColourOverride(true);
