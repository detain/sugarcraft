<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(
    CommandSpec::new('workflow', Lang::t('cmd.workflow.description'), Lang::t('cmd.category.workflow'), paletteAction: PaletteAction::ShowWorkflows),
)->withHandler('handleWorkflowCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\WorkflowCommand::class);
