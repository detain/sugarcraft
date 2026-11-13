<?php

declare(strict_types=1);

/**
 * Run candy-flappy straight from a checkout:
 *   php examples/play.php
 *
 * Prefer ./bin/candy-flappy for real play: it additionally prints
 * --version/--help, refuses unknown flags, migrates the pre-rename
 * high-score directory, and settles a mid-flight score on signal death.
 */
$candidates = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];
foreach ($candidates as $path) {
    if (is_file($path)) {
        require $path;
        break;
    }
}

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flappy\Game;

// flappy-13: mirrors bin/candy-flappy — one profile, probed once, gating
// the alt-screen contract and the colour posture together.
$profile = ColorProfile::detect(null, STDOUT);
$program = new Program(
    Game::start(),
    new ProgramOptions(
        useAltScreen: $profile !== ColorProfile::NoTty,
        hideCursor: $profile !== ColorProfile::NoTty,
        colorProfile: $profile,
    ),
);
$final = $program->run();
if ($final instanceof Game) {
    try {
        $final->settleOnExit();
    } catch (\Throwable) {
        // m5: a final save must never replace the game's exit status —
        // mirrors bin/candy-flappy so the documented swallow holds here too.
    }
}
