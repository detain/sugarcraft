<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(
    CommandSpec::new('instructions', Lang::t('cmd.instructions.description'), Lang::t('cmd.category.instructions'), argumentHint: Lang::t('cmd.instructions.hint')),
)->withHandler('handleInstructionsCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\InstructionsHostCommand::class);
