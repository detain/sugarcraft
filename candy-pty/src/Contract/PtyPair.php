<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * A paired master and slave PTY endpoint.
 *
 * @see portable-pty.PtyPair
 */
interface PtyPair
{
    /**
     * @see creack/pty.Pty.Master()
     */
    public function master(): MasterPty;

    /**
     * @see creack/pty.Pty.Slave()
     */
    public function slave(): SlavePty;
}
