<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::requestTerminalVersion()}. The
 * terminal answers `ESC P > | <terminal name and version> ESC \`
 * (XTVERSION). The `version` field carries everything between the
 * `>|` marker and the ST terminator (e.g. `xterm(367)`,
 * `iTerm2 3.4.16`, `WezTerm 20240203-110809`).
 *
 * Useful for gating capabilities on a specific terminal — for
 * instance, only emitting Kitty graphics on `kitty 0.21+`.
 */
final class TerminalVersionMsg implements Msg
{
    public function __construct(
        public readonly string $version,
    ) {}
}
