<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Generic;

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

    public function init(): array
    {
        return [
            'name' => $this->name(),
            'minSize' => [20, 3],
            'interval' => $this->intervalSeconds,
        ];
    }

    public function update(array $state): array
    {
        $output = $this->runCommand();
        $state['output'] = $output;
        return $state;
    }

    public function view(array $state, int $width, int $height): string
    {
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
