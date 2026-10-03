<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('compact', 'Manually compact chat history to save context', 'Session'),
)->withHandler('handleCompactCommand');
