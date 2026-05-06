<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Generic terminal-capability response message — distinct from the
 * specialised replies ({@see TerminalVersionMsg} for XTVERSION,
 * {@see ModeReportMsg} for DECRQM).
 *
 * Use this for capability queries that don't fit those two shapes.
 * Carry the request name (an identifier the issuer chose) and the
 * raw response string the parser captured. Models match on
 * `$capability` to route the reply to the right handler.
 *
 * ```php
 * // hypothetical: ask the terminal what it supports.
 * Cmd::raw("\x1b[?u");                 // request kitty keyboard flags
 * // ... in update():
 * if ($msg instanceof CapabilityMsg && $msg->capability === 'kitty') {
 *     // parse $msg->response
 * }
 * ```
 *
 * Mirrors upstream `bubbletea.CapabilityMsg`.
 */
final class CapabilityMsg implements Msg
{
    public function __construct(
        /** Caller-supplied identifier for the capability being reported. */
        public readonly string $capability,
        /** Raw response payload (typically the bytes between the OSC/CSI/DCS opener and ST). */
        public readonly string $response,
    ) {}
}
