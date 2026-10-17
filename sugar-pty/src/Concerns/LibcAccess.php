<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Concerns;

use SugarCraft\Pty\Libc;

/**
 * Shared accessor for the process-wide libc FFI handle.
 *
 * Every FFI-touching class used to inline `Libc::lib()`; funnelling
 * the lookup through one accessor keeps singleton access uniform and
 * leaves a single seam if per-instance handles (e.g. an alternate
 * libc for tests) are ever needed.
 */
trait LibcAccess
{
    /**
     * The lazily-loaded, process-cached libc FFI handle.
     *
     * @throws \SugarCraft\Pty\PtyException if libc cannot be loaded
     * @see Libc::lib()
     */
    protected static function libc(): \FFI
    {
        return Libc::lib();
    }
}
