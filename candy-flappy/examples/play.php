<?php

declare(strict_types=1);

/**
 * Run candy-flappy from a checkout:
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Flappy\Game;

(new Program(Game::start(), new ProgramOptions(useAltScreen: true)))->run();
