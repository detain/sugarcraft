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
];
