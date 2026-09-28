<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Backend\EngineBackend;

/**
 * A {@see Tool} whose body is itself an agentic turn, and which therefore needs
 * the engine that is running the CURRENT turn rather than one it builds.
 *
 * {@see EngineBackend::complete()} hands itself to every such tool right before
 * it assembles the turn's App, so the tool the Runtime executes is the bound
 * copy. That is the only construction order that works: the engine is built
 * FROM its tool list, so no tool can hold the engine at construction time, and
 * an engine a tool assembled on its own would carry none of the session's
 * provider switch, hook chain, permission gate, approver, spend cap or root.
 *
 * The heartbeat is the turn's liveness sink. A delegated run can take many
 * minutes, and {@see EngineBackend::completeAsync()}'s parent measures SILENCE
 * on the child socket, so a tool that works quietly for longer than
 * `COMPLETE_TIMEOUT_SECONDS` gets its whole turn killed as hung. Calling the
 * heartbeat is a sign of life and nothing else — it paints nothing. It may be
 * null (a sync caller has no idle deadline) and is a no-op outside the process
 * that bound it, because a forked child writing frames onto the parent's socket
 * would interleave with the parent's own.
 */
interface DelegatesToEngine
{
    /**
     * @param \Closure(): void|null $heartbeat
     */
    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null): Tool;
}
