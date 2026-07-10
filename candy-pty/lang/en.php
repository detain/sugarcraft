<?php

/**
 * English (default) translations for candy-pty.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'open.posix_openpt_failed' => 'posix_openpt() failed (rc={rc}, errno={errno}); /dev/ptmx may be unavailable or restricted — see README "Known limitations"',
    'open.grantpt_failed'      => 'grantpt() failed on master_fd={fd}',
    'open.unlockpt_failed'     => 'unlockpt() failed on master_fd={fd}',
    'open.ptsname_failed'      => 'ptsname_r() failed on master_fd={fd}',
    'open.cloexec_failed'      => 'fcntl(F_SETFD, FD_CLOEXEC) failed on master_fd={fd}; the master would leak into children and tty_hangup()/SIGHUP could never fire',
    'libc.override_not_absolute' => 'SUGARCRAFT_LIBC must be an absolute path, got "{path}" (a relative value would load a shared object from the current working directory)',
    'libc.override_not_file'   => 'SUGARCRAFT_LIBC "{path}" does not exist or is not a regular file; refusing to load it',
    'libc.override_not_libc'   => 'SUGARCRAFT_LIBC "{path}" does not look like a libc (basename must contain "libc", "libSystem", or "musl"); refusing to load an arbitrary shared object',
    'close.failed'             => 'close(master_fd={fd}) failed (rc={rc})',
    'spawn.slave_open_failed'  => 'failed to open slave PTY "{path}" for the child stdio',
    'spawn.proc_open_failed'   => 'proc_open() returned false for command: {cmd}',
    'spawn.no_pid'             => 'proc_open() succeeded but proc_get_status() reported no pid for: {cmd}',
    'process.spawn_failed'     => 'proc_open() returned false while spawning non-PTY process: {cmd}',
    'process.no_pid'           => 'proc_open() succeeded but proc_get_status() reported no pid for non-PTY spawn: {cmd}',
    'spawn.shim_pcntl_required' => 'controllingTerminal:true requires ext-pcntl; install it or set controllingTerminal:false',
    'spawn.shim_not_found'     => 'pty-shim.php not found or unreadable at {path}',
    'resize.failed'            => 'TIOCSWINSZ ioctl failed on master_fd={fd} (cols={cols} rows={rows} rc={rc})',
    'size.failed'              => 'TIOCGWINSZ ioctl failed on master_fd={fd} (rc={rc})',
    'stream.fopen_failed'      => 'php://fd/{fd} could not be opened as a stream',
    'stream.set_blocking_failed' => 'stream_set_blocking({blocking}) failed on master_fd={fd}',
    'write.failed'             => 'fwrite() failed on master_fd={fd} (len={len})',
    'read.select_failed'       => 'stream_select() failed while waiting for master_fd={fd}',
];
