<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Generic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Output\Sanitize;

/**
 * Generic module that runs an arbitrary shell command and displays output.
 *
 * Uses Cmd::tick() for periodic refresh based on intervalSeconds.
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
        return Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            $newOutput = $this->runCommand();
            $nextModule = $this->withOutput($newOutput);
            return [$nextModule, Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg())];
        }
        return [$this, null];
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
     * When $command is a string: shell_exec verbatim (trusted-only path).
     */
    private function runCommand(): string
    {
        if (is_array($this->command)) {
            return $this->runArgv($this->command);
        }
        // Trusted-only string path — callers must not pass user input here.
        $output = @shell_exec($this->command . ' 2>&1');
        return $output !== null ? trim($output) : 'Command failed';
    }

    /**
     * Run an argv array via proc_open with no shell involvement.
     * stderr is merged into stdout so `2>&1` semantics are preserved.
     *
     * @param list<string> $argv
     */
    private function runArgv(array $argv): string
    {
        if ($argv === []) {
            return 'Command failed';
        }

        $process = proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return 'Command failed';
        }

        // Close stdin immediately — no interactive input expected.
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        proc_close($process);

        $combined = $stdout . ($stderr !== '' ? "\n$stderr" : '');
        return trim($combined) !== '' ? trim($combined) : (trim($stdout) !== '' ? trim($stdout) : 'Command failed');
    }
}
