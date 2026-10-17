<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware\Subsystem;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Session;

/**
 * Handles SSH subsystem requests.
 *
 * SSH subsystems are predefined remote commands invoked via
 * `subsystem <name>`. Examples include SFTP (`subsystem sftp`) and
 * port forwarding. Implementations register a handler and receive
 * the active context and session when a matching subsystem request
 * arrives.
 */
interface SubsystemHandler
{
    /**
     * Handle the subsystem request.
     *
     * @param Context $ctx   Cancellation, deadlines, and metadata
     * @param Session $session Active SSH session metadata
     */
    public function handle(Context $ctx, Session $session): void;
}
