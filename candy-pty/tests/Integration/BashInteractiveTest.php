<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

/**
 * End-to-end smoke test for `bash -i` under a real PTY.
 *
 * Mirrors creack/pty integration test for bash — exercise the full
 * spawn → write → drain → reap cycle against the system's interactive
 * Bourne-again shell, with controllingTerminal:true so Ctrl+C-style
 * signals would reach the correct pgroup.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.1)
 */
final class BashInteractiveTest extends InteractiveShellTestCase
{
    private const BASH_PATH = '/usr/bin/bash';

    public function testBashInteractiveEchoRoundTrip(): void
    {
        $this->requirePtySyscalls();
        $this->requireBinary(self::BASH_PATH, 'bash');

        $output = $this->runShellRoundTrip(
            [self::BASH_PATH, '-i'],
            // First line emits the marker; second line cleanly exits
            // the interactive shell so wait() returns promptly.
            "echo hi\nexit\n",
        );

        $this->assertStringContainsString(
            'hi',
            $output,
            'bash -i must echo "hi" within the 5s wallclock budget',
        );
    }
}
