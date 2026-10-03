<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * Opt-in declaration that a {@see Tool} can report "still working" while it
 * waits on something outside this process — a shell command, a `grep`, an MCP
 * server (item 0.4-b).
 *
 * WHY IT EXISTS. A turn runs in a forked completion child, and
 * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} kills that
 * child after 120 s with no frame on its socket — an IDLE ceiling, re-armed
 * by every frame. A parallel group already beats while it waits (see
 * {@see \SugarCraft\Crush\Runtime::executeConcurrently()}), but a call
 * executed alone was silent for its whole run, so a sequential `Bash` build,
 * `Grep` over a huge tree or slow MCP call lost the WHOLE turn at 120 s
 * whatever its own timeout said. With this interface the watchdog measures
 * silence, not work: {@see \SugarCraft\Crush\Runtime::executeSequentially()}
 * hands a throttled beat to {@see executeWithHeartbeat()}, and the tool calls
 * it from inside its own wait loop.
 *
 * WHY A SECOND ENTRY POINT RATHER THAN A BOUND COPY. The built-in tools are
 * `final readonly` value objects, and the beat belongs to ONE call, not to the
 * instance: passing it with the arguments keeps the tool stateless and leaves
 * {@see Tool::execute()} — every other caller's path — byte-for-byte as it
 * was. A SIGALRM ticker was the rejected alternative: a signal-driven frame
 * write can interleave with a frame the turn is already writing.
 *
 * THE CONTRACT. Same result as {@see Tool::execute()} for the same arguments;
 * the beat may be called any number of times (the caller throttles it) and
 * only from the calling process — never from a child the tool forks.
 */
interface AcceptsHeartbeat
{
    /**
     * @param array<string, mixed> $args
     * @param \Closure(): void $heartbeat
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult;
}
