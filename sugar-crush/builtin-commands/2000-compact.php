<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// The optional focus steers the model-written summary (roadmap 2.12): it is
// sent beside the exchanges and handed to PreCompact hooks as
// `custom_instructions`. See Chat::handleCompactCommand().
return BuiltInCommand::new(CommandSpec::new(
    'compact',
    'Manually compact chat history to save context',
    'Session',
    argumentHint: '[focus]',
))->withHandler('handleCompactCommand');
