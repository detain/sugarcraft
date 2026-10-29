<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The registry behind C-MCP-3.d: a bridged/dispatched MCP call that outlives
 * the background threshold keeps running in a forked child, and its eventual
 * answer lands here as a payload file this user's home owns alone.
 *
 * WHY A REGISTRY DIRECTORY AND NOT AN IN-PROCESS TABLE: the child that keeps
 * the exchange may outlive the turn child that forked it (that is the whole
 * feature — the turn moves on). A result written after the forking process
 * has exited has no in-process home to arrive in, so the child publishes the
 * finished {@see ToolResult} — serialized exactly as the forked-turn channel
 * already serializes tool results — into a private directory, and whichever
 * later turn's inbox claims it hands it to the model as a message. The
 * claim is a rename, so two turns draining at once cannot both deliver one
 * job; a claim whose holder died mid-read is garbage-collected on the next
 * scan, and the payload survives to be re-delivered because the GC runs
 * before the claim.
 *
 * WHY THE CHILD CARRIES THE WHOLE RENDER: {@see McpToolBridge}'s answer
 * shaping — fence, truncation, spill, the failed-call error row — is a
 * private instance pipeline. Forking BEFORE it runs, instead of after, means
 * a fast background-armed call and a plain inline call execute the SAME code
 * in the same object, so the ToolResult a fast call returns is byte-for-byte
 * what the same call without this feature returned. Nothing here re-implements
 * a piece of it.
 *
 * WHAT IS NOT A TIMEOUT: {@see await()} releases the TURN at the threshold;
 * it never signals the child. The child's own bound stays exactly what it was
 * before this file existed — its transport's `toolTimeout` — which is the
 * standing law against blanket wall-clock ceilings on a live model/server
 * exchange (E646's family). The threshold is a product hand-off point, fixed
 * by the caller, injectable in tests.
 */
final class McpBackgroundCalls
{
    /**
     * Where the interactive turn hands a still-running MCP call over, in
     * seconds — the parity figure upstream sets for the same hand-off. Zero
     * or negative means "never background", which is every construction site
     * that predates this feature and every non-turn dispatch.
     */
    public const DEFAULT_AFTER_SECONDS = 120.0;

    /** The registry lives under the launch's own home corner, like every other cross-process state. */
    public const RELATIVE_DIR = 'mcp-background';

    /** How long a `.taken` marker may sit before the next scan decides its claimer died holding it. */
    private const CLAIM_STALE_SECONDS = 30;

    /** The parent's poll cadence while it waits below the threshold, microseconds. */
    private const POLL_MICROSECONDS = 20_000;

    /**
     * Children this PROCESS forked and has not reaped yet, keyed by job id.
     * A backgrounded call's child exits at an arbitrary later moment; the
     * registry scan opportunistically reaps its own process's leftovers so a
     * long-lived TUI does not accrete zombies. A child of a turn child that
     * has itself exited reparents to the init system and is reaped there —
     * nothing in this file can waitpid a process it did not fork, and it
     * never pretends to.
     *
     * @var array<string, int>
     */
    private static array $children = [];

    /**
     * Whether an armed MCP tool may take the background path at all: the call
     * is one the TURN is waiting on (a heartbeat channel is present) and it has
     * NOT been handed the turn's ask channel or live tail, a live threshold, a
     * run that is not the closed `-p` latch, the pcntl functions
     * this design forks with, and a home the registry can be kept in. Every
     * failed door means the inline call — the exact behavior from before this
     * feature, byte-identical, including on Windows and every
     * pcntl-less fallback, because backgrounding is an OPT-IN of the turn
     * wiring and a machine that cannot fork simply never gets the fast path
     * replaced.
     *
     * WHY THE TURN DOOR IS HERE AND NOT AT THE CALL SITES: production arms the
     * dial where a turn binds its channels to the tool list
     * ({@see \SugarCraft\Code\Backend\EngineBackend}, the C-MCP-3.d arm of the
     * `AcceptsToolProgress` bind), so a copy of an MCP tool that leaves that
     * bind — a sub-agent's narrowed bridge, a workflow step, a programmatic
     * {@see \SugarCraft\Code\Tools\McpToolBridge::execute()} on a tool that
     * happens to be in reach — can still carry the figure. The heartbeat
     * closure is the answer to which call the turn is actually blocking on:
     * Runtime hands one to {@see AcceptsHeartbeat} tools only while a turn
     * waits, which is precisely the wait the hand-off releases. A call with no
     * beat keeps the inline path, which is also what keeps the fork count of a
     * non-turn dispatch at zero.
     *
     * WHY THE CHANNEL DOOR IS A DOOR AND NOT A RELAY (round-1 ruling, option
     * (b) of the review): a call that has been handed the turn's ASK channel or
     * its live tail is a call whose mid-flight messages need the PARENT — an
     * `elicitation/create` must reach the human and carry an answer back, and a
     * `ToolProgress` frame must reach the renderer that is still drawing the
     * row. The fork hands the child a COPY of those closures: its writes go
     * nowhere useful and its ask can never be answered, because nothing is
     * draining the child while the parent sits in this loop. Relaying asks and
     * tails over the registry side-channel is the other answer and it is the
     * heavier one (a second protocol, in a directory that is deliberately a
     * one-shot result mailbox); the plan does not require a long call to
     * background while it is asking, so the cheaper honest rule wins: an
     * ask-capable or tail-capable call simply is never backgrounded, and every
     * promise about elicitations reaching the human stays the C-MCP-3.b
     * promise, made of one process. The cost is disclosed in docs/MCP.md: on a
     * turn that has bound either channel, a long call keeps blocking, exactly
     * as it did before this feature existed.
     */
    public static function armed(float $afterSeconds, bool $latchedNonInteractive, bool $turnDriven, bool $serverRequestChannelsBound): bool
    {
        return $turnDriven
            && !$serverRequestChannelsBound
            && $afterSeconds > 0.0
            && !$latchedNonInteractive
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && self::directory() !== null;
    }

    /**
     * The registry directory for the CURRENT `HOME`, resolved at every use —
     * no memo, because a HOME-sandboxed test and the real launch must not
     * share a registry through a cached path, and a mid-process home move is
     * exactly how the suite isolates. Null when no private home can be
     * determined, which switches {@see armed()} off.
     */
    public static function directory(): ?string
    {
        $home = HomeDirectory::owned();

        return $home === null ? null : rtrim($home, '/') . '/.candy-code/' . self::RELATIVE_DIR;
    }

    /**
     * Run $exchange in a forked child and wait for its published result,
     * releasing the caller at $afterSeconds with a `background` outcome while
     * the child keeps running.
     *
     * The caller-side contract: `inline` means this process could not fork,
     * so the CALLER must run its own exchange as it always did; `done` hands
     * back the child's ToolResult (success AND failed-call rows arrive this
     * way — the child rendered both, so the bytes are the plain path's);
     * `lost` means the child exited without publishing — a killed or
     * crashed exchange whose answer will never arrive, which callers must
     * report as an error and never as silence; `background` carries the job
     * id the placeholder text names.
     *
     * While waiting, the parent beats the caller's $heartbeat at most once a
     * second — the same cadence the transport's wait beat carried into the
     * turn's idle ceiling before this file existed; the child runs its
     * exchange WITHOUT a beat of its own, so exactly one process writes the
     * turn's frames for any given moment. (What the child does NOT have is an
     * answer channel: a fork hands the child a COPY of the turn's approver and
     * progress closures, so a call that was bound either one is refused by
     * {@see armed()} and never reaches this loop. The desk-relay claim this
     * paragraph used to make was measured false at round 1 — see the door's
     * own note above and docs/MCP.md §Server requests.)
     *
     * @return array{kind: string, result?: ToolResult, job?: string}
     */
    public static function await(\Closure $exchange, float $afterSeconds, ?\Closure $heartbeat, string $server, string $tool, string $callId): array
    {
        $directory = self::directory();
        if ($directory === null) {
            // armed() already refused this pairing; the door stays open here
            // too so a race between the check and the fork degrades inline,
            // never into a child that cannot publish.
            return ['kind' => 'inline'];
        }

        $job = self::newJobId($server, $tool);
        $pid = pcntl_fork();
        if ($pid === 0) {
            self::publishChild($exchange, $directory, $job, $server, $tool, $callId);
        }

        if ($pid === -1) {
            return ['kind' => 'inline'];
        }

        self::$children[$job] = $pid;
        $payload = $directory . '/' . $job;
        $deadline = hrtime(true) + (int) round($afterSeconds * 1_000_000_000);
        $nextBeat = hrtime(true) + 1_000_000_000;
        while (true) {
            $status = 0;
            $reaped = @pcntl_waitpid($pid, $status, WNOHANG);
            if ($reaped === $pid) {
                unset(self::$children[$job]);

                return self::collect($payload, $job);
            }

            $now = hrtime(true);
            if ($now >= $deadline) {
                // One last non-blocking reap before declaring the hand-off:
                // a child that exited a microsecond ago published its answer,
                // and delivering the real result beats promising a message.
                $status = 0;
                $reaped = @pcntl_waitpid($pid, $status, WNOHANG);
                if ($reaped === $pid) {
                    unset(self::$children[$job]);

                    return self::collect($payload, $job);
                }

                return ['kind' => 'background', 'job' => $job];
            }

            if ($heartbeat !== null && $now >= $nextBeat) {
                $heartbeat();
                $nextBeat = $now + 1_000_000_000;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Whether any finished job is waiting in the registry right now — a cheap
     * existence probe, claiming NOTHING: the per-step inbox probe runs on
     * every sequential tool call, and a probe that consumed would deliver the
     * job into the void.
     */
    public static function hasFinished(): bool
    {
        $directory = self::directory();
        if ($directory === null || !is_dir($directory)) {
            return false;
        }

        foreach (@scandir($directory) ?: [] as $name) {
            if (str_ends_with($name, '.payload')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Claim every finished job this process can, oldest first, and return the
     * frames their children published. A claim is the rename every later
     * scanner must lose to be excluded, so one row per job however many
     * turns drain at once; the unlink after the read is what makes a dead
     * claimer's stale marker an obvious GC target on the next scan instead of
     * a permanent silence.
     *
     * @return list<array{job: string, server: string, tool: string, callId: string, result: ToolResult}>
     */
    public static function takeFinished(): array
    {
        self::sweepOwnChildren();
        $directory = self::directory();
        if ($directory === null || !is_dir($directory)) {
            return [];
        }

        $names = @scandir($directory);
        if ($names === false) {
            return [];
        }

        $stale = time() - self::CLAIM_STALE_SECONDS;
        $frames = [];
        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, '.taken')) {
                // The claimer died between rename and unlink. The payload
                // bytes live INSIDE the claimed file, so releasing it back to
                // a re-claimable name is the delivery-preserving repair.
                if (@filemtime($directory . '/' . $name) < $stale && str_ends_with($name, '.payload.taken')) {
                    $restore = substr($name, 0, -strlen('.taken'));
                    @rename($directory . '/' . $name, $directory . '/' . $restore);
                }

                continue;
            }
            if (!str_ends_with($name, '.payload')) {
                continue;
            }
            $files[] = $name;
        }

        sort($files, SORT_STRING);
        foreach ($files as $name) {
            $path = $directory . '/' . $name;
            $claimed = $path . '.taken';
            if (!@rename($path, $claimed)) {
                continue;
            }

            $frame = self::readFrame($claimed);
            @unlink($claimed);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * Children this process forked whose exit has not been collected yet.
     * Pinned by the reaping tests: the fast path must hand this array back
     * EMPTY, and a backgrounded job must appear in it until the registry
     * scan reaps it.
     *
     * @return array<string, int>
     */
    public static function pendingChildren(): array
    {
        return self::$children;
    }

    /** Test seam: forget this process's roster (a sandboxed run never forked into it). */
    public static function resetPendingChildrenForTesting(): void
    {
        self::$children = [];
    }

    /**
     * Publish one finished exchange. The child writes to a sibling `.writing`
     * name and renames, so a `.payload` file that EXISTS is a frame that is
     * COMPLETE — no reader ever has to negotiate a partial write. Both exit
     * legs leave through {@see ForkedChild::exitNow()}: shutdown hooks, loop
     * teardown and terminal teardown from the inheriting turn's state are
     * exactly what a detached grandchild must not run.
     */
    private static function publishChild(\Closure $exchange, string $directory, string $job, string $server, string $tool, string $callId): never
    {
        try {
            $result = $exchange();
            self::publish($directory, $job, $server, $tool, $callId, $result);
        } catch (\Throwable) {
            // Nothing was published, and nothing can be: the point of the
            // failed leg is the file's ABSENCE, which the collector reads as
            // `lost`. A write that itself threw must not become an uncaught
            // fatal inside a detached child either.
        }

        ForkedChild::exitNow(0);
    }

    /**
     * @return ?array{job: string, server: string, tool: string, callId: string, result: ToolResult}
     */
    private static function publish(string $directory, string $job, string $server, string $tool, string $callId, ToolResult $result): ?array
    {
        PrivateDir::ensure($directory, 'MCP background registry');
        $frame = [
            'job' => $job,
            'server' => $server,
            'tool' => $tool,
            'callId' => $callId,
            'result' => $result,
        ];
        $path = $directory . '/' . $job;
        $writing = $path . '.writing';
        $handle = @fopen($writing, 'xb');
        if ($handle === false) {
            return null;
        }

        @chmod($writing, 0600);
        $ok = @fwrite($handle, serialize($frame)) !== false;
        fclose($handle);
        if (!$ok || !@rename($writing, $path . '.payload')) {
            @unlink($writing);

            return null;
        }

        return $frame;
    }

    /**
     * @return ?array{job: string, server: string, tool: string, callId: string, result: ToolResult}
     */
    private static function readFrame(string $claimedPath): ?array
    {
        $bytes = @file_get_contents($claimedPath);
        if ($bytes === false) {
            return null;
        }

        // The same trust level as the turn channel's frames — bytes this
        // user's own 0700 registry corner received from this harness's own
        // child — so no class allow-list; the shape check below is what
        // refuses a tampered or truncated file.
        $frame = @unserialize($bytes);

        return is_array($frame)
            && ($frame['result'] ?? null) instanceof ToolResult
            && is_string($frame['job'] ?? null)
            && is_string($frame['server'] ?? null)
            && is_string($frame['tool'] ?? null)
            && is_string($frame['callId'] ?? null)
            ? $frame : null;
    }

    /**
     * @return array{kind: string, result?: ToolResult, job?: string}
     */
    private static function collect(string $payloadBase, string $job): array
    {
        $path = $payloadBase . '.payload';
        // Claim with the same rename protocol a concurrent inbox scan uses:
        // whoever wins the rename alone reads the finished exchange. A parent
        // that loses to a draining inbox cannot hand back the result object,
        // and says `lost` — the model still learns the answer, because the
        // claimer delivered it as a message row; the disclosed cost of a
        // sub-millisecond race between two readers of one child's output.
        if (!@rename($path, $path . '.taken')) {
            return ['kind' => 'lost'];
        }

        $frame = self::readFrame($path . '.taken');
        @unlink($path . '.taken');

        return $frame === null ? ['kind' => 'lost'] : ['kind' => 'done', 'result' => $frame['result']];
    }

    private static function sweepOwnChildren(): void
    {
        foreach (self::$children as $job => $pid) {
            $status = 0;
            if (@pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                unset(self::$children[$job]);
            }
        }
    }

    /**
     * Millisecond-prefixed so a lexical sort is a delivery order, with entropy
     * behind it so two processes that name the same millisecond still publish
     * two files. The server/tool words ride the front of the id because the
     * placeholder quotes it to a human: `git-slowread-…` is legible in a
     * transcript, `f3a1b9…` is not.
     */
    private static function newJobId(string $server, string $tool): string
    {
        $slug = static fn (string $word): string => substr(preg_replace('/[^a-z0-9_-]/i', '', $word) ?? '', 0, 24);

        return sprintf(
            '%s-%s-%013d-%s',
            $slug($server),
            $slug($tool),
            (int) (microtime(true) * 1000),
            bin2hex(random_bytes(4)),
        );
    }
}
