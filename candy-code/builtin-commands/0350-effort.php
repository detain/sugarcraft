<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;

// The reasoning dial (PRV-2, §6.5): bare reads it, a tier sets it, `auto` hands
// it back to the model. Parsed args like /model — the level is one token and
// the parser has already unquoted it. Deliberately no palette action: the dial
// belongs next to the model, not in its picker, and a palette row that refused
// half the providers would be a trap with a nicer frame.
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
))->withHandler('handleEffortCommand', CommandArguments::Parsed)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\EffortHostCommand::class);
