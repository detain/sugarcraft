<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Palette\PaletteAction;

// Optional, and the three forms do different things: bare `/model` opens the
// same provider list Ctrl+P's Switch Model opens, `/model <provider>` switches
// straight to that one, and `/model <provider> <model>` switches to that model
// on it and saves the choice in `models` (N-P3b). See Chat::handleModelCommand().
//
// The only handler that wants the PARSED arguments rather than the raw text: a
// provider name and a model id are one token each, and CommandParser has
// already unquoted them.
return BuiltInCommand::new(CommandSpec::new(
    'model',
    'Switch the active provider, or a provider and its model',
    'Model',
    paletteAction: PaletteAction::SwitchModel,
    paletteLabel: 'Switch model',
    argumentHint: '[provider [model]]',
))->withHandler('handleModelCommand', CommandArguments::Parsed);
