<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(
    CommandSpec::new('instructions', Lang::t('cmd.instructions.description'), Lang::t('cmd.category.instructions'), argumentHint: Lang::t('cmd.instructions.hint'), paletteAction: PaletteAction::ShowInstructions),
)->withHandler('handleInstructionsCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\InstructionsHostCommand::class);
