<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Result of a {@see \SugarCraft\Core\Cmd::exec()} run. Carries:
 *
 *  - `$exitCode`   — process exit status (0 on success).
 *  - `$error`      — proc_open / fork failure as a Throwable (null if
 *                    the process started cleanly).
 *  - `$stdout`     — captured stdout (when `captureOutput=true` was
 *                    passed to `Cmd::exec()`).
 *  - `$stderr`     — captured stderr.
 *
 * Models inspect this in `update()` to know whether the editor /
 * pager / external command succeeded. Mirrors Bubble Tea's
 * `tea.ExecMsg`.
 */
final class ExecMsg implements Msg
{
    public function __construct(
        public readonly int $exitCode,
        public readonly ?\Throwable $error = null,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->exitCode === 0;
    }
}
