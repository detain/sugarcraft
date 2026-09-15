#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * pty-shim — claims fd 0 (the slave PTY wired by proc_open) as the
 * controlling terminal of a fresh session, then exec's the real cmd
 * so signals (SIGINT on Ctrl+C, SIGWINCH on resize, SIGHUP on master
 * close) reach it via the kernel's tty layer.
 *
 * Invoked by Pty::spawn(..., controllingTerminal: true) as
 *
 *   php pty-shim.php [--autoload=<path>] <cmd> [args...]
 *
 * The `--autoload=` token is a spawn-internal hand-off (see the resolution
 * ladder below); it is consumed here and never reaches <cmd>.
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
//   1. `--autoload=<path>` prepended by Spawn::wrapInShim(). The parent
//      process already knows which autoloader satisfies ITS classes, and
//      the symlinked-vendor shape below makes anything the shim re-derives
//      on its own a guess again — so prefer the fact handed down. The token
//      is consumed here and never reaches the exec'd command.
//   2. Around the REAL script directory (__DIR__). The shim ships inside
//      candy-pty/bin/ but may run from:
//      - candy-pty itself (top-level): ../vendor/autoload.php
//      - inside a copied parent vendor (vendor/sugarcraft/candy-pty/bin/): ../../../autoload.php
//      - via Composer's vendor-bin install: ../../../../autoload.php
//   3. The CONSUMER VENDOR ROOT read lexically off the AS-INVOKED script
//      path ($argv[0] — PHP resolves __DIR__ through symlinks but leaves
//      argv[0] as passed). A Composer path-repo install — the monorepo's
//      linked shape, where vendor/sugarcraft/candy-pty is a symlink whose
//      checkout target carries no vendor/ of its own — sends (2) into the
//      wrong tree, while the invocation path still names the consumer's
//      `.../vendor/sugarcraft/...` span. The extraction is deliberately
//      LEXICAL: appending `..` candidates to a symlinked path and letting
//      the kernel resolve them fails, because `..` is resolved physically
//      AFTER symlink traversal and walks up from the real target, never
//      landing on the consumer's vendor. Cutting at the first `/vendor/`
//      and requiring `<root>/autoload.php` involves no upward traversal
//      at all, so it resolves exactly where the autoloader lives.
$autoloadFlagPrefix = '--autoload=';

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
 * `.../consumer/vendor/sugarcraft/candy-pty/bin/pty-shim.php`
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

// Consume the hand-off token at its ONE contract position: Spawn::wrapInShim
// places it between the script and the command, so without an injected token
// argv[1] IS the command. Deliberately anchored, not scanned — a deeper scan
// would let a user argument shaped like `--autoload=…` be eaten and required
// in place of being exec'd whenever Spawn declined to inject (no identifiable
// entrypoint autoloader).
$explicitAutoload = null;
if (isset($argv[1]) && \str_starts_with($argv[1], $autoloadFlagPrefix)) {
    $explicitAutoload = \substr($argv[1], \strlen($autoloadFlagPrefix));
    unset($argv[1]);
    $argv = \array_values($argv);
}

$loadedAutoload = null;
if ($explicitAutoload !== null) {
    if ($explicitAutoload === '' || !\is_file($explicitAutoload)) {
        \fwrite(\STDERR, 'pty-shim: --autoload file not found: ' . $explicitAutoload . "\n");
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

if (\count($argv) < 2) {
    \fwrite(\STDERR, "pty-shim: usage: pty-shim.php [--autoload=<path>] <cmd> [args...]\n");
    exit(2);
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
