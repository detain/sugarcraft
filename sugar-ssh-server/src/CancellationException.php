<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

final class CancellationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('context cancelled');
    }
}
