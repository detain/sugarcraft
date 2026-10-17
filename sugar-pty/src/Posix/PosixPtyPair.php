<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

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
     * Narrowed (covariantly) to the concrete {@see PosixMasterPty}.
     *
     * @see creack/pty.Pty.Master()
     */
    public function master(): PosixMasterPty
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
