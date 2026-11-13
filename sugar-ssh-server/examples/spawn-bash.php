<?php

/**
 * Default-mode (in-process supervisor) sugar-ssh-server example.
 *
 * Spawns a fresh login bash inside a sugar-pty for each connecting
 * SSH user. Ctrl+C, vim, less, tmux all work properly because the
 * inner shell has its own controlling terminal (set by sugar-pty's
 * pty-shim).
 *
 * Wire via sshd's ForceCommand exactly like the pre-PTY-upgrade
 * deployment — the difference is purely internal to the PHP process:
 *
 * /etc/ssh/sshd_config.d/wish.conf:
 *
 *   Match User wishuser
 *       ForceCommand /usr/bin/php /path/to/this/file.php
 *       PermitTTY yes
 *
 * Compared to bare ForceCommand-to-bash, this version gets you:
 * - a composable middleware stack (this specimen registers Logger +
 *   Spawn; everything else is one ->use() away)
 * - ability to swap the inner cmd per-user via the Spawn factory
 * - clean process isolation (supervisor crash doesn't kill the shell;
 *   shell exit cleanly tears down the supervisor)
 *
 * WHAT THIS EXAMPLE DELIBERATELY OMITS. There is no `Auth` and no
 * `RateLimit` in this stack, so *any* user sshd lets through lands in a
 * login bash, and nothing throttles a script hammering the endpoint —
 * that is the open-default posture the library README tabulates under
 * 'Defaults that are open until you close them': fine for a smoke test,
 * not a deployment. Unlike hello-server.php this specimen hands out a
 * shell, which makes the omission the whole difference between a demo
 * and an exposure.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Middleware\Spawn;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

exit(Server::new()
    // InProcessTransport is the default — explicit
    // ->withTransport() is unnecessary but documents the choice.
    ->use(Logger::auto())
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => ['/bin/bash', '-l'],
        'env' => [
            'TERM'    => $s->term,
            'USER'    => $s->user,
            'LOGNAME' => $s->user,
            'HOME'    => "/home/{$s->user}",
            'PATH'    => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG'    => $s->lang,
        ],
    ]))
    ->serve());
