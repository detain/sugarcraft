<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * Opt-in declaration that a {@see Tool} needs the id of the call it is
 * executing, not just the call's arguments.
 *
 * WHY IT EXISTS. {@see Tool::execute()} receives only the arguments the model
 * sent, and no argument carries the call id: a tool that read `$args['id']`
 * read an empty string on every engine call. For `Task` that cost every link
 * from a delegated run back to the call that started it — the
 * `SubAgentActivity` beats went out with an empty `parentCallId`, so the TUI's
 * live lines and the web's agent tree could not hang a run under its `Task`
 * row, and a `cancel_tool{callId}` aimed at that call never matched the run.
 *
 * THE CONTRACT. {@see \SugarCraft\Crush\Runtime} sets `$args['id']` to the
 * call's own id for a tool that implements this interface — in-process and in
 * a forked parallel member alike — OVERWRITING any `id` the model supplied, so
 * the value is the engine's and cannot be forged. Every other tool's arguments
 * are left exactly as the model sent them: an MCP bridge forwards its
 * arguments to a server verbatim, and must not grow a key the server never
 * declared.
 */
interface TakesToolCallId
{
}
