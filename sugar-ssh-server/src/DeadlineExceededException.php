<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

final class DeadlineExceededException extends \RuntimeException
{
    /**
     * The optional reason rides the fixed text as
     * `context deadline exceeded: <reason>` (audit sugar-ssh-server-078).
     * With no reason the message is the plain fixed string, so anything
     * matching on it today keeps matching.
     */
    public function __construct(string $reason = '')
    {
        parent::__construct($reason === '' ? 'context deadline exceeded' : 'context deadline exceeded: ' . $reason);
    }
}
