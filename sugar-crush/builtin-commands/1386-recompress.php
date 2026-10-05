<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.B-4 (DCP `/dcp recompress`): /decompress undone.
return BuiltInCommand::new(CommandSpec::new(
    'recompress',
    'Restore the summary of a section /decompress took back',
    'App',
    argumentHint: '[bN]',
))->withHandler('handleRecompressCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RecompressHostCommand::class);
