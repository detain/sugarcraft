<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

return BuiltInCommand::new(
    CommandSpec::new('workflow', Lang::t('cmd.workflow.description'), Lang::t('cmd.category.workflow')),
)->withHandler('handleWorkflowCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\WorkflowCommand::class);
