<?php

/**
 * Default-mode (in-process supervisor) candy-wish example.
 *
 * Spawns a fresh login bash inside a candy-pty for each connecting
 * SSH user. Ctrl+C, vim, less, tmux all work properly because the
 * inner shell has its own controlling terminal (set by candy-pty's
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
 * - middleware composition (Logger, Auth, RateLimit, custom)
 * - ability to swap the inner cmd per-user via the Spawn factory
 * - clean process isolation (supervisor crash doesn't kill the shell;
 *   shell exit cleanly tears down the supervisor)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;

Server::new()
    // InProcessTransport is the default in PR1+ — explicit
    // ->withTransport() is unnecessary but documents the choice.
    ->use(new Logger())
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
    ->serve();
