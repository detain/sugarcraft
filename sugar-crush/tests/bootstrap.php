<?php

declare(strict_types=1);

use SugarCraft\Crush\Support\ToolIpcFiles;

require __DIR__ . '/../vendor/autoload.php';

/*
 * A temp directory for the suite's own throwaway files, and the two things that
 * keep `vendor/bin/phpunit` from garbage-collecting the developer's real /tmp.
 *
 * ToolIpcFiles::sweepOnce() is wired into Bootstrap::backend()/backendFor(),
 * and a dozen test files reach those — so whichever one PHPUnit happened to run
 * first spent the process's one sweep on the REAL sys_get_temp_dir(), unlinking
 * every sc_chat_tool_* / sc_runtime_tool_* payload over an hour old that this
 * uid owns. Harmless for the suite, hostile to the machine: running the tests
 * is not a request to reap another sugar-crush's files.
 *
 * 1. The latch is per-process and idempotent, so tripping it here leaves no
 *    sweep for any test to spend on the real directory. ToolIpcFilesTest resets
 *    it by reflection where it needs to exercise the sweep itself.
 *
 * 2. TMPDIR covers the real `bin/sugarcrush` processes eighteen test files
 *    spawn, which get a genuine startup sweep of their own that no in-process
 *    latch can reach. It works on a CHILD and only on a child: `putenv()` does
 *    not move the temp directory of the process that calls it (PHP has already
 *    resolved sys_get_temp_dir(), so this suite keeps building its sandboxes
 *    under the real one and every test that does so keeps working), but a child
 *    is a fresh process that resolves it after inheriting this. Tests that hand
 *    their child a whitelist environment forward it explicitly.
 *
 * What that leaves is one test: ToolIpcFilesTest's wiring proof deliberately
 * resets the latch so a real Bootstrap::backend() sweep runs, in-process, on
 * the real temp directory. That is the production contract executing as
 * written — abandoned payloads of ours, older than an hour, owned by this uid —
 * and it is the only place the suite reaches the machine's /tmp at all.
 *
 * The directory is stable rather than per-run, and is never torn down: it has
 * to outlive the run (PHP silently falls back to /tmp when TMPDIR names
 * anything that is not a writable directory, which would put the children right
 * back on the real one), a shutdown hook would be inherited by every forked
 * child and run at the wrong time, and the sweep above prunes it on the next
 * run anyway. Per-uid because /tmp is shared.
 */
$sandbox = sys_get_temp_dir() . '/sc_suite_tmp_' . (function_exists('posix_geteuid') ? posix_geteuid() : 'x');
@mkdir($sandbox, 0o700, true);

ToolIpcFiles::sweepOnce($sandbox);

putenv('TMPDIR=' . $sandbox);
