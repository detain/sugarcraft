<?php

/**
 * In-process candy-wish example that mounts a SugarCraft Program
 * inside a candy-pty subprocess (the in-process equivalent of the
 * pre-PTY-upgrade BubbleTea + HostSshd setup).
 *
 * Two pieces:
 *
 *   1. This file (the supervisor) — registers Spawn middleware
 *      with a factory that returns the path to a tiny wrapper
 *      script that runs the actual Program.
 *
 *   2. `run-program.php` (the wrapper) — a few lines that
 *      autoload candy-core, instantiate the user's Model, and
 *      call `(new Program($model))->run()`. Lives next to this
 *      file in production deployments.
 *
 * The wrapper runs as a subprocess under the candy-pty PTY, so
 * Ctrl+C / Ctrl+Z / SIGWINCH all behave correctly via the
 * controlling-terminal claim. The supervisor middleware layer
 * (Logger, Auth, RateLimit) runs in the parent, before the
 * wrapper is exec'd — same composition model as the legacy
 * BubbleTea pattern, just with process isolation.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;

$wrapper = __DIR__ . '/run-program.php';

Server::new()
    ->use(new Logger())
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => [PHP_BINARY, $wrapper, $s->user, (string) $s->cols, (string) $s->rows],
        'env' => [
            'TERM' => $s->term,
            'LANG' => $s->lang,
            'PATH' => '/usr/bin:/bin',
        ],
    ]))
    ->serve();

/*
 * Example wrapper (`run-program.php`):
 *
 * ----------
 * #!/usr/bin/env php
 * <?php
 * declare(strict_types=1);
 *
 * require __DIR__ . '/../vendor/autoload.php';
 *
 * use SugarCraft\Core\Program;
 *
 * [$user, $cols, $rows] = [$argv[1] ?? 'guest', (int) ($argv[2] ?? 80), (int) ($argv[3] ?? 24)];
 *
 * $model = new MyApp(user: $user, cols: $cols, rows: $rows);
 * (new Program($model))->run();
 * ----------
 */
