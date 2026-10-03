<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Palette\PaletteAction;

// Optional, and the two forms do different things: bare `/model` opens the
// same provider list Ctrl+P's Switch Model opens, `/model <provider>` switches
// straight to that one. See Chat::handleModelCommand().
//
// The only handler that wants the PARSED arguments rather than the raw text: a
// provider name is one token, and CommandParser has already unquoted it.
return BuiltInCommand::new(CommandSpec::new(
    'model',
    'Switch the active model provider',
    'Model',
    paletteAction: PaletteAction::SwitchModel,
    paletteLabel: 'Switch model',
    argumentHint: '[provider]',
))->withHandler('handleModelCommand', CommandArguments::Parsed);
