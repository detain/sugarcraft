<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Generic;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Msg\TickMsg;
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
     * True while the command is on the loop. Its output returns as a
     * CommandOutputMsg. Each tick schedules the next one itself, so one tick
     * chain runs at a time and a hung command never stacks children.
     */
    private bool $inFlight = false;

    /**
     * Monotonic second the in-flight latch was taken. The latch only holds for
     * as long as its child can still settle; see {@see latched()}.
     */
    private ?float $inFlightSince = null;

    /**
     * Counts every run started. The newest value names the run the latch belongs
     * to: a result from an older, still-hung run must neither clear the latch nor
     * schedule a second tick chain beside the live one.
     */
    private int $runSeq = 0;

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
        private readonly ?string $name = null,
    ) {
    }

    public function name(): string
    {
        return $this->name ?? 'generic';
    }

    public function init(): ?\Closure
    {
        return $this->selfTickCmd();
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof CommandOutputMsg && $msg->source === $this->name()) {
            // Only the newest run settles the latch; an older run that was still hung
            // past the ceiling is stale and changes nothing.
            if (!$this->inFlight || $msg->run !== $this->runSeq) {
                return [$this, null];
            }

            return [$this->withOutput($msg->output), null];
        }

        if ($msg instanceof TickMsg && $msg->isFor($this->name())) {
            // The tick re-arms itself on every path, as SystemModule does. A cmd-only
            // host that drops the run's Cmd then still gets the next tick, and with it
            // the stale-latch expiry; re-arming only on the output would freeze the module.
            if ($this->latched()) {
                return [$this, $this->selfTickCmd()];
            }

            $next = $this->withInFlight();
            $tick = $next->selfTickCmd();
            $run = $next->runCmd();

            return [$next, $tick === null ? $run : Cmd::batch($tick, $run)];
        }

        return [$this, null];
    }

    protected function tickIntervalSeconds(): ?float
    {
        return (float) $this->intervalSeconds;
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
        $clone->inFlight = false;
        $clone->inFlightSince = null;
        return $clone;
    }

    private function withInFlight(): static
    {
        $clone = clone $this;
        $clone->inFlight = true;
        $clone->inFlightSince = hrtime(true) / 1_000_000_000;
        $clone->runSeq = $this->runSeq + 1;
        return $clone;
    }

    /**
     * A latch holds only while its child can still settle. The Cmd belongs to
     * the host: a host that drops it never delivers CommandOutputMsg, so past
     * the settle ceiling the latch is stale and the next tick runs the command
     * again. Inside the ceiling a live child is never stacked.
     */
    private function latched(): bool
    {
        if (!$this->inFlight) {
            return false;
        }

        $elapsed = hrtime(true) / 1_000_000_000 - (float) $this->inFlightSince;

        return $elapsed < BoundedCommand::SETTLE_CEILING_SECONDS;
    }

    /**
     * The configured command as a Cmd: it runs on the loop through BoundedCommand
     * and settles as a CommandOutputMsg carrying the trimmed stdout+stderr (or the
     * named failure). When $command is an array: proc_open with no shell (safe for
     * untrusted args). The string form is wrapped in `sh -c` for shell syntax and
     * is a trusted-only configuration path. The command string is passed
     * VERBATIM (sugar-dashboard-200): appending `2>&1` was string surgery
     * on configuration — it silently hijacked a user's own `2>/dev/null`
     * (last redirect wins) and poured stderr into `> file` targets, while
     * BoundedCommand already merges the err pipe on its own.
     */
    private function runCmd(): \Closure
    {
        $argv = is_array($this->command)
            ? $this->command
            : ['sh', '-c', $this->command];
        $source = $this->name();
        $run = $this->runSeq;

        return Cmd::promise(
            static fn (): PromiseInterface => BoundedCommand::runAsync(Loop::get(), $argv)->then(
                static fn (array $result): Msg => new CommandOutputMsg(
                    $source,
                    $result['failure'] ?? $result['output'],
                    $run,
                ),
                // Unexpected rejection: name it rather than leave the module in flight forever.
                static fn (\Throwable $e): Msg => new CommandOutputMsg($source, 'Command failed', $run),
            ),
        );
    }
}
