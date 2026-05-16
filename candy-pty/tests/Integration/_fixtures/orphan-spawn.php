<?php

declare(strict_types=1);

/**
 * Fixture for {@see \SugarCraft\Pty\Tests\Integration\OrphanedChildReapTest}.
 *
 * Spawns `/bin/sleep 0.5` against a fresh PTY pair, prints the child's
 * PID to stdout, then exits IMMEDIATELY without calling `$child->wait()`.
 *
 * The point is to abandon the child mid-flight: the parent test process
 * cannot orphan its own child (PHPUnit's process needs to keep running),
 * so a sacrificial subprocess does the abandon for us. Once this script
 * exits the kernel reparents the inner sleep to init (PID 1) and either
 * init or our {@see \SugarCraft\Pty\Posix\ChildPollTrait::pollDestruct()}
 * safety net reaps it before the test's wallclock budget expires.
 *
 * Runs via `php tests/Integration/_fixtures/orphan-spawn.php`.
 * Stdout is a single line: the orphaned child's PID, decimal, `\n`-terminated.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.5)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Pty\PtySystemFactory;

$system = PtySystemFactory::default();
$pair = $system->open(80, 24);

$child = $pair->slave()->spawn(
    ['/bin/sleep', '0.5'],
    [
        'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
        'LANG' => 'C',
        'LC_ALL' => 'C',
    ],
    80,
    24,
    controllingTerminal: true,
);

// Surface the PID so the test can poll /proc/<pid> for disappearance.
\fwrite(\STDOUT, $child->pid() . "\n");
\fflush(\STDOUT);

// Exit WITHOUT $child->wait() and WITHOUT $master->close(). This is the
// whole point of the fixture: orphan the child so the test can confirm
// the kernel + pollDestruct() safety net reap it cleanly.
exit(0);
