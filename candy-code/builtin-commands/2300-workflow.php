<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(
    CommandSpec::new('workflow', Lang::t('cmd.workflow.description'), Lang::t('cmd.category.workflow')),
)->withHandler('handleWorkflowCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\WorkflowCommand::class);
