<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// The reasoning dial (PRV-2, §6.5): bare reads it, a tier sets it, `auto` hands
// it back to the model. Parsed args like /model — the level is one token and
// the parser has already unquoted it. CC-UXD-04 adopted the row the refusal
// feared: Enter on the palette runs the bare verb, which READS the dial; the
// refusal gate answers windows that cannot act — setting still names a tier.
//
// The TUI handler and the served command (EffortHostCommand, C-WEB-2.f) turn
// the same pure dial — EffortDial — and both persist the tier through the
// workspace's user-tier SettingsWriter (the door ModelHostCommand opened; the
// dial, unlike /fast's lane, is saved on both surfaces).
return BuiltInCommand::new(CommandSpec::new(
    'effort',
    Lang::t('cmd.effort.description'),
    Lang::t('cmd.category.model'),
    argumentHint: Lang::t('cmd.effort.hint'),
    paletteAction: PaletteAction::ShowEffort,
))->withHandler('handleEffortCommand', CommandArguments::Parsed)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\EffortHostCommand::class);
