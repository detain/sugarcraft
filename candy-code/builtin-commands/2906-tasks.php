<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// The one place a user sees all background work at once: shells, background
// sessions and sub-agent runs, with tail and stop for shells. Bare `/tasks`
// IS the list, so the palette row can run it without an argument.
return BuiltInCommand::new(
    CommandSpec::new('tasks', Lang::t('cmd.tasks.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.tasks.hint'), paletteAction: PaletteAction::ShowTasks),
)->withHandler('handleTasksCommand')
    ->withHostCommand(\SugarCraft\Code\Commands\TasksCommand::class);
