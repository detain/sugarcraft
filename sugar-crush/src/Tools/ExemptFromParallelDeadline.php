<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * Marks a {@see ParallelSafe} tool whose forked child must NOT be SIGKILLed at
 * {@see \SugarCraft\Crush\Runtime}'s parallel-tool group deadline.
 *
 * The deadline exists to bound tools that should finish in seconds (`Read`,
 * `Grep`, `WebFetch`). A tool whose body is a whole agentic run — the `Task`
 * delegation — legitimately runs for tens of minutes, and killing it at 90s
 * is a blanket total-request timeout on LLM work by another name. Siblings in
 * the same group keep their deadline; only the exempt job waits it out.
 *
 * Declaring this is a promise the tool bounds ITSELF: an exempt child that
 * never exits holds its turn open forever. `TaskTool` keeps it by capping its
 * run at the preset's `maxTurns` and exiting once the process that forked it
 * is gone (see {@see DelegatesToEngine}'s heartbeat).
 */
interface ExemptFromParallelDeadline
{
}
