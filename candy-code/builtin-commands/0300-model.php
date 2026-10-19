<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Optional, and the three forms do different things: bare `/model` opens the
// same provider list Ctrl+P's Switch Model opens, `/model <provider>` switches
// straight to that one, and `/model <provider> <model>` switches to that model
// on it and saves the choice in `models` (N-P3b). See Chat::handleModelCommand().
//
// The only handler that wants the PARSED arguments rather than the raw text: a
// provider name and a model id are one token each, and CommandParser has
// already unquoted them.
//
// WEB-2: ModelHostCommand serves the same three forms headless — the roster
// from Bootstrap::availableProviders(), the switch built through the
// workspace's provider factory — as a SESSION-scoped override; the `models`
// settings write stays the terminal's, and the row says so.
return BuiltInCommand::new(CommandSpec::new(
    'model',
    Lang::t('cmd.model.description'),
    Lang::t('cmd.category.model'),
    paletteAction: PaletteAction::SwitchModel,
    paletteLabel: Lang::t('cmd.model.label'),
    argumentHint: Lang::t('cmd.model.hint'),
))->withHandler('handleModelCommand', CommandArguments::Parsed)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ModelHostCommand::class);
