<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Generic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Generic module that runs an arbitrary shell command and displays output.
 *
 * Uses Cmd::tick() for periodic refresh based on intervalSeconds.
 */
final class GenericModule extends BaseModule
{
    private string $output = '';

    public function __construct(
        private readonly string $command,
        private readonly int $intervalSeconds = 5,
    ) {
    }

    public function name(): string
    {
        return 'generic';
    }

    public function init(): ?\Closure
    {
        return Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        $newOutput = $this->runCommand();
        $nextModule = $this->withOutput($newOutput);
        if ($msg instanceof TickMsg) {
            return [$nextModule, Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg())];
        }
        return [$nextModule, null];
    }

    public function view(): string
    {
        return $this->output;
    }

    public function minSize(): array
    {
        return [20, 3];
    }

    private function withOutput(string $output): static
    {
        $clone = clone $this;
        $clone->output = $output;
        return $clone;
    }

    private function runCommand(): string
    {
        $output = @shell_exec($this->command . ' 2>&1');
        return $output !== null ? trim($output) : 'Command failed';
    }
}
