<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('workflow', 'Run, pause, resume, or inspect a workflow', 'Workflow'),
)->withHandler('handleWorkflowCommand');
