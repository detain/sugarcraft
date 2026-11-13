#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * pty-shim — claims fd 0 (the slave PTY wired by proc_open) as the
 * controlling terminal of a fresh session, then exec's the real cmd
 * so signals (SIGINT on Ctrl+C, SIGWINCH on resize, SIGHUP on master
 * close) reach it via the kernel's tty layer.
 *
 * Invoked by Spawn::wrapInShim() for controllingTerminal:true spawns as
 *
 *   php pty-shim.php <cmd> [args...]
 *
 * The autoloader hand-off (see the resolution ladder below) rides the
 * SUGAR_PTY_SHIM_AUTOLOAD environment variable, staged only by
 * Spawn — argv carries no token at all, so a command shaped like
 * `--autoload=…` is a command, never a file this shim will require.
 *
 * Sequence (mirrors creack/pty's `Open()` post-fork branch):
 *
 *   1. ControllingTerminal::claim(0)  — setsid() + ioctl(TIOCSCTTY, 0)
 *   2. pcntl_exec(cmd, args)             — replace process image with cmd.
 *
 * Exit codes (only reached on shim errors before exec):
 *   2  <cmd> absent (usage), or pcntl missing
 *   3  autoload unresolvable or impotent, FFI unavailable, or claim failed
 *   6  pcntl_exec failed (cmd not found, ENOEXEC, etc.)
 *
 * Once pcntl_exec succeeds the shim's PHP image is gone — exit code
 * comes from the cmd itself.
 *
 * Logic for step 1 lives in SugarCraft\Pty\ControllingTerminal::claim()
 * so it can be called from other contexts without invoking the shim.
 */

// Resolve vendor autoload so we can use ControllingTerminal / Libc.
// Resolution ladder, first hit wins:
//   1. SUGAR_PTY_SHIM_AUTOLOAD, staged into the child's environment by
//      Spawn::proc(). The parent process already knows which autoloader
//      satisfies ITS classes, and the symlinked-vendor shape below makes
//      anything the shim re-derives on its own a guess again — so prefer
//      the fact handed down. The variable is consumed here and never
//      reaches the exec'd command's own environment bookkeeping beyond
//      inheritance; its value is never sourced from argv, which is the
//      defence (see the consume block): the old shape read argv[1] as a
//      hand-off slot, and a caller-shaped `cmd[0]="--autoload=/x.php"`
//      reached the require whenever Spawn declined to inject.
//   2. Around the REAL script directory (__DIR__). The shim ships inside
//      sugar-pty/bin/ but may run from:
//      - sugar-pty itself (top-level): ../vendor/autoload.php
//      - inside a copied parent vendor (vendor/sugarcraft/sugar-pty/bin/): ../../../autoload.php
//      - via Composer's vendor-bin install: ../../../../autoload.php
//   3. The CONSUMER VENDOR ROOT read lexically off the AS-INVOKED script
//      path ($argv[0] — PHP resolves __DIR__ through symlinks but leaves
//      argv[0] as passed). A Composer path-repo install — the monorepo's
//      linked shape, where vendor/sugarcraft/sugar-pty is a symlink whose
//      checkout target carries no vendor/ of its own — sends (2) into the
//      wrong tree, while the invocation path still names the consumer's
//      `.../vendor/sugarcraft/...` span. The extraction is deliberately
//      LEXICAL: appending `..` candidates to a symlinked path and letting
//      the kernel resolve them fails, because `..` is resolved physically
//      AFTER symlink traversal and walks up from the real target, never
//      landing on the consumer's vendor. Cutting at the first `/vendor/`
//      and requiring `<root>/autoload.php` involves no upward traversal
//      at all, so it resolves exactly where the autoloader lives.
$probe = static function (string $binDir): ?string {
    foreach ([
        $binDir . '/../vendor/autoload.php',
        $binDir . '/../../../autoload.php',
        $binDir . '/../../../../autoload.php',
    ] as $candidate) {
        if (\is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
};

/**
 * Lexical consumer-vendor-root probe — see ladder step 3 above.
 *
 * `.../consumer/vendor/sugarcraft/sugar-pty/bin/pty-shim.php`
 *   → `.../consumer/vendor/autoload.php` (returned only when it exists).
 */
$probeVendorRoot = static function (string $invokedScript): ?string {
    $position = \strpos($invokedScript, '/vendor/');
    if ($position === false) {
        return null;
    }
    $candidate = \substr($invokedScript, 0, $position) . '/vendor/autoload.php';

    return \is_file($candidate) ? $candidate : null;
};

// The usage refusal comes FIRST: a plain argv mistake must exit 2 before
// anything reaches the require, which is the whole point of the next block
// (the old order required the autoloader, and only then checked whether the
// invocation made sense — so a poisoned hand-off was already live by the
// time the "usage" could have refused it).
if (\count($argv) < 2) {
    \fwrite(\STDERR, "pty-shim: usage: pty-shim.php <cmd> [args...]\n");
    exit(2);
}

// Consume the hand-off token from the ENVIRONMENT, where only Spawn can put
// it — Spawn::proc() stages the variable around the child's proc_open and
// scrubs inherited or caller-supplied values, so a token that is present
// names a path THIS package computed in THIS process's own bootstrap. argv
// is never consulted for an autoload path (the old anchored argv[1] slot was
// reachable by user-shaped arguments whenever Spawn declined to inject);
// an env the child explicitly inherits is written by the parent under the
// same contract. A hand-set value is still just a path this user-run process
// would require — same privilege class as any php invocation — so the env
// carrier is about CONTRACT, not sandboxing.
$explicitAutoload = \getenv('SUGAR_PTY_SHIM_AUTOLOAD');
if ($explicitAutoload === false || $explicitAutoload === '') {
    $explicitAutoload = null;
}

$loadedAutoload = null;
if ($explicitAutoload !== null) {
    if (!\is_file($explicitAutoload)) {
        \fwrite(\STDERR, 'pty-shim: SUGAR_PTY_SHIM_AUTOLOAD file not found: ' . $explicitAutoload . "\n");
        exit(3);
    }
    require $explicitAutoload;
    $loadedAutoload = $explicitAutoload;
} else {
    $autoload = $probe(__DIR__)
        ?? ($argv === [] ? null : $probeVendorRoot((string) $argv[0]));
    if ($autoload === null) {
        \fwrite(\STDERR, 'pty-shim: vendor/autoload.php not found near ' . __DIR__
            . ' nor via the invoked path ' . (string) ($argv[0] ?? '?') . "\n");
        exit(3);
    }
    require $autoload;
    $loadedAutoload = $autoload;
}

// Fail loud, fail honest: an autoload that RESOLVED but does not define
// this package's machinery (a foreign composer root the lexical cut
// happened to land on) must surface as the documented exit-3 autoload
// error, not as an uncaught class-not-found fatal several lines below.
// Ordered after the usage branch so stub autoloads (no classes, argv
// exhausted) still exit 2 as every resolution test's discriminator.
if (!\class_exists(\SugarCraft\Pty\ControllingTerminal::class)) {
    \fwrite(\STDERR, 'pty-shim: loaded autoload does not define ControllingTerminal: '
        . (string) $loadedAutoload . "\n");
    exit(3);
}

if (!\extension_loaded('pcntl')) {
    \fwrite(\STDERR, "pty-shim: ext-pcntl required for pcntl_exec\n");
    exit(2);
}

if (!\extension_loaded('ffi')) {
    \fwrite(\STDERR, "pty-shim: ext-ffi required for setsid + ioctl(TIOCSCTTY)\n");
    exit(3);
}

try {
    \SugarCraft\Pty\ControllingTerminal::claim(0);
} catch (\SugarCraft\Pty\PtyException $e) {
    \fwrite(\STDERR, "pty-shim: ControllingTerminal::claim failed: {$e->getMessage()}\n");
    exit(3);
}

// argv[0] is the script name, argv[1] is the real cmd, argv[2..] are args.
$scriptName = \array_shift($argv);
$cmd = \array_shift($argv);

if (!\is_string($cmd) || $cmd === '') {
    \fwrite(\STDERR, "pty-shim: empty cmd after shim args\n");
    exit(2);
}

// pcntl_exec inherits the env from the calling proc (proc_open already
// applied the user-supplied env array). It returns false on failure
// and the script keeps running; on success the process image is gone.
\pcntl_exec($cmd, $argv);

\fwrite(\STDERR, "pty-shim: pcntl_exec({$cmd}) failed — cmd not executable?\n");
exit(6);
