<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.B-4 (DCP `/dcp decompress`). It writes the session's context
// ledger, so it is not read-only.
return BuiltInCommand::new(CommandSpec::new(
    'decompress',
    'Send a compressed section in full again (no argument: list the sections)',
    'App',
    argumentHint: '[bN]',
))->withHandler('handleDecompressCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\DecompressHostCommand::class);
