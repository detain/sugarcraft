<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * A tool whose entire effect on the world is nested tool calls made while it
 * runs — the {@see Script} catalog tool today (C-TOOL-6.d), whose PHP child
 * reaches the parent process over the fd-3 bridge of
 * {@see \SugarCraft\Code\Tools\Script\ScriptRunner}.
 *
 * The law this interface exists to keep: a nested call goes through the SAME
 * permission gate, the SAME hook chain and the SAME
 * {@see \SugarCraft\Code\Runtime} execution arms as a direct call the model
 * made itself. There is no second validator — a script's `call('Edit', …)`
 * is judged exactly like the model typing that tool call, ask-for-ask,
 * deny-for-deny, hook-for-hook. The bridge is a transport, not a privilege:
 * anything that treated it as one would be the bypass class of defect this
 * family was audited for.
 *
 * {@see withNestedDispatch()} is called by the Runtime on the way INTO
 * {@see ScriptTool::executeWithHeartbeat()} — the bound pair closes over the
 * live outer call, the App and the UI's permission callback, so the nested
 * arm reaches the same `$onPermissionRequest` the outer ask reaches. A tool
 * built but never bound (a test, a stray reference) must refuse loudly
 * rather than run with nothing bound: an unbound nested dispatch is an
 * effect-less script, and silence about that is a fabricated success path.
 */
interface DispatchesNestedTools
{
    /**
     * This tool with the nested-call plumbing attached; every other field
     * kept (the copy-on-write `with*` law).
     *
     * @param \Closure(string, array<array-key, mixed>): ToolResult $call
     *        invoked once per accepted bridge `call` frame with the script's
     *        tool name and arguments; answers the settled inner
     *        {@see ToolResult} — same gate, same hooks, denial included.
     * @param \Closure(): list<array{name: string, signature?: string}> $catalog
     *        answers the budgeted rows the script's `tools()` inspects: the
     *        names currently dispatchable and a one-line signature each.
     */
    public function withNestedDispatch(\Closure $call, \Closure $catalog): static;
}
