<?php

declare(strict_types=1);

/**
 * Run honey-flap from a checkout:
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Flap\Game;

(new Program(Game::start(), new ProgramOptions(useAltScreen: true)))->run();
