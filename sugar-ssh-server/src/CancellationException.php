<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

final class CancellationException extends \RuntimeException
{
    /**
     * The optional reason rides the fixed text as
     * `context cancelled: <reason>` (audit sugar-ssh-server-078) — a
     * bare throw used to be the only shape this channel could carry.
     * `Context::cancel()` hands a supplied Throwable through to `err()`
     * verbatim, so `cancel(new CancellationException('hangup received'))`
     * is the blessed way to attach a why without defining a class of
     * your own.
     */
    public function __construct(string $reason = '')
    {
        parent::__construct($reason === '' ? 'context cancelled' : 'context cancelled: ' . $reason);
    }
}
