<?php

declare(strict_types=1);

namespace SugarCraft\Code;

use SugarCraft\Core\Msg;

/**
 * MCP-5: one progress beat from the forked `mcp auth login` child.
 *
 * WHY A FORK AND NOT THE E701 GUIDANCE: the loopback login owns a terminal
 * for the whole browser leg — it prints a URL, waits on a local HTTP
 * callback, and answers — and the TUI owns THIS terminal. E701's answer was
 * "guide and never run"; MCP-5 upgrades the guide to a real in-TUI login by
 * giving the child its own pipes: {@see Support\McpLoginFork} spawns
 * `candy-code mcp auth login <server>` as a separate process, the URL the
 * child prints is announced into the transcript, and the human finishes the
 * browser leg while the TUI keeps painting. Nothing in Chat blocks.
 *
 * The beats are a small machine, and the tick loop only re-arms while the
 * machine says it is still running: `waiting` (child alive, no news),
 * `url` (the child printed its authorize URL — first time only), `done`
 * (the child exited; text carries its tail, exitCode its status), and
 * `gone` (the slot vanished — a reset tore the child down; stop re-arming,
 * print nothing).
 */
final class McpLoginProgressMsg implements Msg
{
    public const PHASE_WAITING = 'waiting';
    public const PHASE_URL = 'url';
    public const PHASE_DONE = 'done';
    public const PHASE_GONE = 'gone';

    /**
     * @param string $server    the server the child logs into (the slot key)
     * @param string $phase     one of the PHASE_* constants
     * @param string $text      the URL for `url`; the child's tail for `done`; '' otherwise
     * @param int    $exitCode  the child's exit status, meaningful only for `done`
     */
    public function __construct(
        public readonly string $server,
        public readonly string $phase,
        public readonly string $text = '',
        public readonly int $exitCode = 0,
    ) {}
}
