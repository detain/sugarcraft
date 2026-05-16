<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\SlavePty;

/**
 * @see portable-pty.PtyPair
 */
final class PosixPtyPair implements PtyPair
{
    public function __construct(
        private readonly PosixMasterPty $master,
        private readonly string $slavePath,
    ) {}

    /**
     * @see creack/pty.Pty.Master()
     */
    public function master(): MasterPty
    {
        return $this->master;
    }

    /**
     * @see creack/pty.Pty.Slave()
     */
    public function slave(): SlavePty
    {
        return new PosixSlavePty($this->slavePath, $this->master);
    }
}
