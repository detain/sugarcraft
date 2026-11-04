<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// C-TUI-5.a. The in-session half of `candy-code doctor`: the same roster of
// install checks, rendered as transcript rows. Arguments are IGNORED, for
// /status's stated reason — the report is already total, and a narrower one
// would read as a different answer from the same command. CC-UXD-04: the
// palette row runs the checks — Enter dispatches /doctor exactly like the
// typed spelling; the model-facing `doctor` TOOL remains the agent's door.
return BuiltInCommand::new(CommandSpec::new(
    'doctor',
    Lang::t('cmd.doctor.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::RunDoctor,
))->withHandler('handleDoctorCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\DoctorHostCommand::class);
