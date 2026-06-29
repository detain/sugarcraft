<?php

declare(strict_types=1);

/**
 * Run candy-mines from a checkout:
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Mines\Game;

(new Program(Game::start(width: 12, height: 10, mines: 14), new ProgramOptions(useAltScreen: true, mouseMode: MouseMode::CellMotion)))->run();
