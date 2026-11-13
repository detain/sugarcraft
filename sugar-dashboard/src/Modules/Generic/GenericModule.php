<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Generic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Module\BoundedCommand;
use SugarCraft\Core\Util\Sanitize;

/**
 * Generic module that runs a configured command and displays output.
 *
 * Uses Cmd::tick() for periodic refresh based on intervalSeconds.
 *
 * Both command forms run through BoundedCommand — deadline, output cap,
 * and a guaranteed TERM→KILL reap — so no tick can ever hand the
 * dashboard's liveness to a child. The string form additionally runs
 * through `sh -c`: it exists for shell syntax (pipes, redirects) and is
 * TRUSTED-ONLY configuration; the argv form executes without shell
 * interpolation and is the safe shape for anything argument-shaped.
 * Command children inherit the full parent environment by design
 * (operator-authored, like a crontab entry) — the untrusted-binary
 * allow-list policy lives on the plugin side (ExternalModule).
 */
final class GenericModule extends BaseModule
{
    private string $output = '';

    /**
     * @param string|array<string> $command  Trusted shell string OR argv list (no shell).
     *                                      When array, runs via proc_open with no shell
     *                                      interpolation — the safe form for untrusted args.
     *                                      When string, passed verbatim to the shell —
     *                                      callers MUST treat as trusted-only input.
     */
    public function __construct(
        private readonly string|array $command,
        private readonly int $intervalSeconds = 5,
    ) {
    }

    public function name(): string
    {
        return 'generic';
    }

    public function init(): ?\Closure
    {
        return $this->selfTickCmd();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            $newOutput = $this->runCommand();
            $nextModule = $this->withOutput($newOutput);
            return [$nextModule, $this->selfTickCmd()];
        }
        return [$this, null];
    }

    protected function tickIntervalSeconds(): ?float
    {
        return (float) $this->intervalSeconds;
    }

    protected function tickProducer(): ?\Closure
    {
        static $produce = null;
        return $produce ??= static fn(): Msg => new TickMsg();
    }

    public function view(): string
    {
        return Sanitize::untrusted($this->output);
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

    /**
     * Run the configured command and return trimmed stdout+stderr.
     * When $command is an array: proc_open with no shell (safe for untrusted args).
     * Both forms run through BoundedCommand; the string form is wrapped
     * in `sh -c` for shell syntax and is a trusted-only configuration path.
     */
    private function runCommand(): string
    {
        $argv = is_array($this->command)
            ? $this->command
            : ['sh', '-c', $this->command . ' 2>&1'];

        $result = BoundedCommand::run($argv);

        return $result['failure'] ?? $result['output'];
    }
}
