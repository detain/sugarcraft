<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Generic;

use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Generic module that runs an arbitrary shell command and displays output.
 */
final class GenericModule extends BaseModule
{
    public function __construct(
        private readonly string $command,
        private readonly int $intervalSeconds = 5,
    ) {}

    public function name(): string
    {
        return 'generic';
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $output = $this->runCommand();
        return [$this->withState(['output' => $output]), null];
    }

    public function view(): string
    {
        $state = $this->getState();
        return $state['output'] ?? '';
    }

    public function minSize(): array
    {
        return [20, 3];
    }

    private function runCommand(): string
    {
        $output = @shell_exec($this->command . ' 2>&1');
        return $output !== null ? trim($output) : 'Command failed';
    }
}
