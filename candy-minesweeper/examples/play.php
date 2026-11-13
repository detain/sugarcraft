<?php

declare(strict_types=1);

/**
 * Run candy-minesweeper from a checkout:
 *   php examples/play.php
 *
 * Construction mirrors bin/candy-minesweeper: translated UI, the fleet
 * colour authority applied before any Style is minted, and the same
 * alt-screen / mouse / bracketed-paste promise set.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\I18n\T;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Style\Style;

T::setLocale(T::detect());

$profile = ColorProfile::detect(null, STDOUT);
if ($profile === ColorProfile::Ascii || $profile === ColorProfile::NoTty) {
    Style::setDefaultProfile($profile);
}

(new Program(Game::start(width: 12, height: 10, mines: 14), new ProgramOptions(
    useAltScreen: $profile !== ColorProfile::NoTty,
    mouseMode: $profile !== ColorProfile::NoTty ? MouseMode::CellMotion : MouseMode::Off,
    bracketedPaste: true,
)))->run();
