#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * pty-shim — claims fd 0 (the slave PTY wired by proc_open) as the
 * controlling terminal of a fresh session, then exec's the real cmd
 * so signals (SIGINT on Ctrl+C, SIGWINCH on resize, SIGHUP on master
 * close) reach it via the kernel's tty layer.
 *
 * Invoked exclusively by Pty::spawn(..., controllingTerminal: true).
 * Not for direct use.
 *
 * Sequence (mirrors creack/pty's `Open()` post-fork branch):
 *
 *   1. ControllingTerminal::claim(0)  — setsid() + ioctl(TIOCSCTTY, 0)
 *   2. pcntl_exec(cmd, args)             — replace process image with cmd.
 *
 * Exit codes (only reached on shim errors before exec):
 *   2  pcntl missing
 *   3  FFI unavailable or ControllingTerminal::claim failed
 *   6  pcntl_exec failed (cmd not found, ENOEXEC, etc.)
 *
 * Once pcntl_exec succeeds the shim's PHP image is gone — exit code
 * comes from the cmd itself.
 *
 * Logic for step 1 lives in SugarCraft\Pty\ControllingTerminal::claim()
 * so it can be called from other contexts without invoking the shim.
 */

// Resolve vendor autoload so we can use ControllingTerminal / Libc.
$autoload = \dirname(__DIR__) . '/vendor/autoload.php';
if (!\is_file($autoload)) {
    \fwrite(\STDERR, "pty-shim: vendor/autoload.php not found\n");
    exit(3);
}
require $autoload;

if (\count($argv) < 2) {
    \fwrite(\STDERR, "pty-shim: usage: pty-shim.php <cmd> [args...]\n");
    exit(2);
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
