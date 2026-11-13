<?php

/**
 * In-process sugar-ssh-server example that mounts a SugarCraft Program
 * inside a sugar-pty subprocess (the in-process equivalent of the
 * pre-PTY-upgrade BubbleTea + HostSshd setup).
 *
 * Two pieces:
 *
 *   1. This file (the supervisor) — registers Spawn middleware
 *      with a factory that returns the path to a tiny wrapper
 *      script that runs the actual Program.
 *
 *   2. `run-program.php` (the wrapper, next to this file) —
 *      a few lines that autoload sugar-core, instantiate the
 *      user's Model, and call `(new Program($model))->run()`.
 *      This is the file the factory below returns, so the pair
 *      has to stay together in a deployment.
 *
 * The wrapper runs as a subprocess under the sugar-pty PTY, so
 * Ctrl+C / Ctrl+Z / SIGWINCH all behave correctly via the
 * controlling-terminal claim. The supervisor's registered middleware
 * runs in the parent, before the wrapper is exec'd — same composition
 * model as the legacy BubbleTea pattern, just with process isolation.
 *
 * WHAT THIS EXAMPLE DELIBERATELY OMITS. The composition model can run
 * gating layers in the parent, but this file registers none: the
 * supervisor stack is Logger + Spawn — no `Auth`, no `RateLimit` — so
 * any user sshd lets through mounts the Program, unhurried. That is
 * the open-default posture the library README tabulates under
 * 'Defaults that are open until you close them': fine for a smoke
 * test, not a deployment. The pair with run-program.php still has to
 * stay together in a deployment; what it lacks is identity and
 * throttle, and both are one ->use() away in the parent.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Middleware\Spawn;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

$wrapper = __DIR__ . '/run-program.php';

exit(Server::new()
    ->use(Logger::auto())
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => [PHP_BINARY, $wrapper, $s->user, (string) $s->cols, (string) $s->rows],
        'env' => [
            'TERM' => $s->term,
            'LANG' => $s->lang,
            'PATH' => '/usr/bin:/bin',
        ],
    ]))
    ->serve());

/*
 * The wrapper this supervisor execs is a sibling file:
 * {@see ./run-program.php}. Run the pair with:
 *
 *   php examples/spawn-program.php
 *
 * or the wrapper alone, on a plain terminal, with:
 *
 *   php examples/run-program.php alice 80 24
 */
