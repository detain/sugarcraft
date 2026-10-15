<?php

declare(strict_types=1);

/**
 * Run candy-minesweeper from a checkout:
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Minesweeper\Game;

(new Program(Game::start(width: 12, height: 10, mines: 14), new ProgramOptions(useAltScreen: true, mouseMode: MouseMode::CellMotion)))->run();
