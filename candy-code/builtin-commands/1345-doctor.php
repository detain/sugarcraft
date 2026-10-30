<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// C-TUI-5.a. The in-session half of `candy-code doctor`: the same roster of
// install checks, rendered as transcript rows. Arguments are IGNORED, for
// /status's stated reason — the report is already total, and a narrower one
// would read as a different answer from the same command. No paletteAction:
// /doctor is a destination, not a mode the palette switches, and the checks
// are the model-facing `doctor` TOOL's job to run, not a palette state.
return BuiltInCommand::new(CommandSpec::new(
    'doctor',
    Lang::t('cmd.doctor.description'),
    Lang::t('cmd.category.app'),
))->withHandler('handleDoctorCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\DoctorHostCommand::class);
