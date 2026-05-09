<?php

/**
 * English (default) translations for candy-pty.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'open.posix_openpt_failed' => 'posix_openpt() failed (rc={rc}); /dev/ptmx may be unavailable or restricted',
    'open.grantpt_failed'      => 'grantpt() failed on master_fd={fd}',
    'open.unlockpt_failed'     => 'unlockpt() failed on master_fd={fd}',
    'open.ptsname_failed'      => 'ptsname_r() failed on master_fd={fd}',
    'close.failed'             => 'close(master_fd={fd}) failed (rc={rc})',
    'spawn.proc_open_failed'   => 'proc_open() returned false for command: {cmd}',
    'spawn.no_pid'             => 'proc_open() succeeded but proc_get_status() reported no pid for: {cmd}',
    'spawn.shim_pcntl_required' => 'controllingTerminal:true requires ext-pcntl; install it or set controllingTerminal:false',
    'spawn.shim_not_found'     => 'pty-shim.php not found or unreadable at {path}',
    'resize.failed'            => 'TIOCSWINSZ ioctl failed on master_fd={fd} (cols={cols} rows={rows} rc={rc})',
    'size.failed'              => 'TIOCGWINSZ ioctl failed on master_fd={fd} (rc={rc})',
    'stream.fopen_failed'      => 'php://fd/{fd} could not be opened as a stream',
    'stream.set_blocking_failed' => 'stream_set_blocking({blocking}) failed on master_fd={fd}',
    'write.failed'             => 'fwrite() failed on master_fd={fd} (len={len})',
    'read.select_failed'       => 'stream_select() failed while waiting for master_fd={fd}',
];
