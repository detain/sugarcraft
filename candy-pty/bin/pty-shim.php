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
 *   1. setsid()                     — new session, calling proc is leader.
 *   2. ioctl(0, TIOCSCTTY, 0)       — make slave PTY the ctty.
 *   3. pcntl_exec(cmd, args)        — replace process image with cmd.
 *
 * Exit codes (only reached on shim errors before exec):
 *   2  pcntl missing
 *   3  ffi missing or libc load failed
 *   4  setsid failed (already a session leader, etc.)
 *   5  TIOCSCTTY failed (slave already someone's ctty, EPERM)
 *   6  pcntl_exec failed (cmd not found, ENOEXEC, etc.)
 *
 * Once pcntl_exec succeeds the shim's PHP image is gone — exit code
 * comes from the cmd itself.
 */

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

// Match Libc::libraryPath() exactly — same env override, same defaults.
$libcPath = \getenv('SUGARCRAFT_LIBC');
if (!\is_string($libcPath) || $libcPath === '') {
    $libcPath = PHP_OS_FAMILY === 'Darwin'
        ? '/usr/lib/libSystem.B.dylib'
        : 'libc.so.6';
}

try {
    $libc = \FFI::cdef(
        'int setsid(void); int ioctl(int fd, unsigned long request, void *arg);',
        $libcPath,
    );
} catch (\FFI\Exception $e) {
    \fwrite(\STDERR, "pty-shim: failed to load libc from {$libcPath}: {$e->getMessage()}\n");
    exit(3);
}

if ($libc->setsid() === -1) {
    \fwrite(\STDERR, "pty-shim: setsid() failed (already session leader?)\n");
    exit(4);
}

// TIOCSCTTY: Linux 0x540E, macOS 0x20007461. Third arg is read by the
// kernel as `unsigned long` — passing NULL pointer (PHP null) renders
// as 0 ("don't steal an existing ctty from another session").
$tioCSctty = PHP_OS_FAMILY === 'Darwin' ? 0x20007461 : 0x540E;
if ($libc->ioctl(0, $tioCSctty, null) !== 0) {
    \fwrite(\STDERR, "pty-shim: ioctl(0, TIOCSCTTY, 0) failed\n");
    exit(5);
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
