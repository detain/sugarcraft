<?php

declare(strict_types=1);

/**
 * Build a fang-style CLI page using every CandyKit primitive.
 *
 *   php examples/cli-page.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Kit\Banner;
use CandyCore\Kit\HelpText;
use CandyCore\Kit\Section;
use CandyCore\Kit\Stage;
use CandyCore\Kit\StatusLine;
use CandyCore\Kit\Theme;

$theme = Theme::dracula();

echo Banner::title('myapp', 'A demo CLI built with CandyKit', $theme) . "\n\n";

echo Section::header('Setup', $theme, leftPad: 2, width: 60) . "\n";
echo Stage::step(1, 4, 'Reading config',  $theme) . "\n";
echo Stage::step(2, 4, 'Validating env',  $theme) . "\n";
echo Stage::subStep('checking PATH',           $theme, isLast: false) . "\n";
echo Stage::subStep('checking composer cache', $theme, isLast: true)  . "\n";
echo Stage::step(3, 4, 'Installing deps',  $theme) . "\n";
echo Stage::step(4, 4, 'Done',             $theme) . "\n\n";

echo Section::header('Status', $theme, width: 60) . "\n";
echo StatusLine::success('All packages installed', $theme) . "\n";
echo StatusLine::warn('1 minor version available', $theme) . "\n";
echo StatusLine::info('Cache size: 12 MB',         $theme) . "\n";
echo "\n";

echo HelpText::render(
    usage: 'myapp [flags] <command>',
    sections: [
        'Commands' => [
            'setup'    => 'configure your environment',
            'install'  => 'install dependencies',
            'serve'    => 'start the dev server',
        ],
        'Flags' => [
            '-v, --verbose'   => 'enable verbose logging',
            '-c, --config <path>' => 'use a custom config file',
            '--theme <name>'  => 'pick a colour theme',
        ],
    ],
    description: 'A demo of CandyKit\'s HelpText helper.',
    theme: $theme,
) . "\n";
