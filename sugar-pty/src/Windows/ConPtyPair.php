<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Contract\PtyPair;

/**
 * The ConPTY master/slave pair.
 *
 * Same shape as {@see \SugarCraft\Pty\Posix\PosixPtyPair}: the master is a
 * fixed object (it owns the pseudo console and all four pipe handles), the
 * slave is a view that can be re-derived on demand — ConPTY has no slave
 * device to open, so handing out a fresh adapter costs nothing.
 *
 * @see ConPtySystem::open() — the only producer
 */
final class ConPtyPair implements PtyPair
{
    public function __construct(private readonly ConPtyMasterPty $master)
    {
    }

    public function master(): ConPtyMasterPty
    {
        return $this->master;
    }

    public function slave(): ConPtySlavePty
    {
        return new ConPtySlavePty($this->master);
    }
}
