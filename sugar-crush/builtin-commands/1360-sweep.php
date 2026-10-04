<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.B-2 (DCP `/dcp sweep`). Next to `/context`, which shows what this
// frees. It writes the session's context ledger, so it is not read-only.
return BuiltInCommand::new(CommandSpec::new(
    'sweep',
    'Prune the tool outputs since your last prompt (or the last n) from what the model sees',
    'App',
    argumentHint: '[n]',
))->withHandler('handleSweepCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\SweepHostCommand::class);
