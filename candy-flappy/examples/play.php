<?php

declare(strict_types=1);

/**
 * Run candy-flappy straight from a checkout:
 *   php examples/play.php
 *
 * Prefer ./bin/candy-flappy for real play: it additionally answers
 * --version/--help, refuses unknown flags, migrates the pre-rename
 * high-score directory, refuses a non-TTY stdout before launch, and exits
 * 128+signo when a signal ends the run — this example always exits 0 once
 * the loop returns, settling on every path like the bin does.
 *
 * flappy-56 (finding -013): this file used to accept no flags at all, so
 * `php examples/play.php --version` launched the TUI instead of answering —
 * the minimal flag legs and the stdout preflight below mirror the bin.
 */
$candidates = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];
foreach ($candidates as $path) {
    if (is_file($path)) {
        require $path;
        break;
    }
}

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Support\Version;

// flappy-56: diagnostic flags answer before anything else runs (bin law).
$usage = "candy-flappy example runner — a Flappy-Bird-style game for the terminal\n\n"
    . "Usage:\n"
    . "  php examples/play.php            Play\n"
    . "  php examples/play.php --version  Print the version\n"
    . "  php examples/play.php -h, --help Show this help\n"
    . "\n"
    . "space/Enter/up/w flap · r restart · q/Esc quit — needs a 62x21 terminal\n";

$flags = array_slice($argv ?? [], 1);
if (in_array('--version', $flags, true)) {
    fwrite(STDOUT, Version::line());
    exit(0);
}
if (in_array('--help', $flags, true) || in_array('-h', $flags, true)) {
    fwrite(STDOUT, $usage);
    exit(0);
}
$unknown = array_values(array_diff($flags, ['--version', '--help', '-h']));
if ($unknown !== []) {
    foreach ($unknown as $bad) {
        $clean = preg_replace('/\p{C}+/u', '?', $bad);
        fwrite(STDERR, 'candy-flappy: unknown argument: ' . ($clean ?? (string) preg_replace('/[\x00-\x1f\x7f]+/', '?', $bad)) . "\n");
    }
    fwrite(STDERR, "\n" . $usage);
    exit(2);
}

// flappy-56 mirroring flappy-01: a pipe gets a plain refusal, not escape
// bytes into a buffer. The colour force-rungs opt back in on purpose.
$forceRung = static fn (string $name): bool => (string) (getenv($name) ?? '') !== ''
    && (string) (getenv($name) ?? '') !== '0'
    && strtolower((string) getenv($name)) !== 'false';
if (!stream_isatty(STDOUT) && !$forceRung('CLICOLOR_FORCE') && !$forceRung('FORCE_COLOR')) {
    fwrite(STDERR, "candy-flappy: this game needs an interactive terminal on stdout.\nRun it in a terminal window, or set FORCE_COLOR=1 to draw into a pipe on purpose.\n");
    exit(1);
}

// flappy-13: mirrors bin/candy-flappy — one profile, probed once, gating
// the alt-screen contract and the colour posture together.
$profile = ColorProfile::detect(null, STDOUT);
$program = new Program(
    Game::start(),
    new ProgramOptions(
        useAltScreen: $profile !== ColorProfile::NoTty,
        hideCursor: $profile !== ColorProfile::NoTty,
        colorProfile: $profile,
        // flappy-09: tap-to-flap, mirroring the bin (minesweeper CellMotion).
        mouseMode: $profile !== ColorProfile::NoTty ? MouseMode::CellMotion : MouseMode::Off,
        // flappy-20: blur freezes the world, focus resumes it debt-free.
        reportFocus: true,
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
