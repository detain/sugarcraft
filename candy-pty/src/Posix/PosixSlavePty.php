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
     * @see creack/pty.Start()
     * @see portable-pty.SlavePty.Start()
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = 80,
        int $rows = 24,
        bool $controllingTerminal = false,
    ): \SugarCraft\Pty\Contract\Child {
        if ($this->master === null) {
            throw new \RuntimeException('Cannot spawn without a master PTY');
        }

        $master = new Master($this->master->fd(), $this->path);

        if ($controllingTerminal) {
            $this->master->resize($cols, $rows);
        }

        return new ChildAdapter(Spawn::proc($master, $cmd, $env, $controllingTerminal));
    }
}
