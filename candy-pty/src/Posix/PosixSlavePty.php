<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\Master;
use SugarCraft\Pty\Spawn;

/**
 * @see creack/pty.Pty
 * @see portable-pty.SlavePty
 */
final class PosixSlavePty implements SlavePty
{
    public function __construct(
        private readonly string $path,
        private readonly ?PosixMasterPty $master = null,
    ) {}

    /**
     * @see creack/pty.Pty.Name()
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Spawn `$cmd` on this slave.
     *
     * Geometry is a property of the PTY, not of the controlling-terminal
     * relationship: TIOCSWINSZ on the master sets the size every opener
     * of the slave reads, whether or not the child calls setsid() +
     * TIOCSCTTY. So an explicit `$cols`/`$rows` is applied on EVERY
     * spawn — creack/pty.StartWithSize() resizes before starting the
     * command and never consults the session. Omitting both keeps the
     * size the pair was opened (or last resized) with, the way
     * creack/pty.Start() and portable-pty's SlavePty::spawn_command()
     * leave the winsize alone. Supplying only one keeps the other half
     * of the current size.
     *
     * The widening to `?int = null` (the contract declares `int = 80/24`)
     * is what lets "no geometry requested" be told apart from an explicit
     * 80x24; a parameter may accept more than its interface does.
     *
     * @see creack/pty.Start()
     * @see creack/pty.StartWithSize()
     * @see portable-pty.SlavePty.Start()
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        ?int $cols = null,
        ?int $rows = null,
        bool $controllingTerminal = false,
    ): \SugarCraft\Pty\Contract\Child {
        if ($this->master === null) {
            throw new \RuntimeException('Cannot spawn without a master PTY');
        }

        $geometry = null;
        if ($cols !== null || $rows !== null) {
            $current = ($cols === null || $rows === null) ? $this->master->size() : null;
            $geometry = [
                $cols ?? $current['cols'],
                $rows ?? $current['rows'],
            ];

            // Before the child exists, so its very first TIOCGWINSZ (a
            // shell sizing its prompt, `stty size`) already reads the
            // requested size instead of racing a post-spawn write. A
            // failure here throws: nothing has been started yet, and a
            // child the caller asked for at 132x50 must not run at 80x24.
            $this->master->resize($geometry[0], $geometry[1]);
        }

        $master = new Master($this->master->fd(), $this->path);

        $child = Spawn::proc($master, $cmd, $env, $controllingTerminal);

        if ($geometry !== null) {
            // Re-assert AFTER proc_open has opened the slave path: macOS
            // xnu resets the winsize when a fresh slave fd is opened, which
            // would clobber the pre-spawn write. Best-effort because that
            // write already succeeded — on every kernel that does not reset
            // on open, the geometry is correct whether or not this lands.
            try {
                $this->master->resize($geometry[0], $geometry[1]);
            } catch (\SugarCraft\Pty\PtyException) {
                // Pre-spawn resize stands; see above.
            }
        }

        return $child;
    }
}
