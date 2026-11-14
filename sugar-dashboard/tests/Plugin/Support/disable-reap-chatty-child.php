<?php

declare(strict_types=1);

/**
 * Chatty fake plugin for the D-100 disable-seam reap pin (sugar-dashboard-094).
 *
 * Announces its pid, then floods unparsable lines on stdout forever and
 * never reads stdin. The host disables the panel on the very first line;
 * this child must be found gone at the disable seam, not parked until the
 * destructor runs. It survives our pipe closures (PHP CLI does not die on
 * a broken stdout write), so only the TERM→KILL ladder ends it — exactly
 * the wedged-child shape the reap had to move up to cover.
 */
$pidFile = $argv[1] ?? null;
if ($pidFile !== null && $pidFile !== '') {
    file_put_contents($pidFile, (string) getmypid());
}

while (true) {
    @fwrite(STDOUT, "not-json\n");
    usleep(20000);
}
