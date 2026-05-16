<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

/**
 * End-to-end smoke test for `dash -i` under a real PTY.
 *
 * Mirrors creack/pty integration test for POSIX sh — dash is the
 * strictly-POSIX baseline, so a passing round-trip proves candy-pty
 * doesn't lean on bash-only features (job control, line editing,
 * readline) for basic interactive use.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.1)
 */
final class DashTest extends InteractiveShellTestCase
{
    private const DASH_PATH = '/usr/bin/dash';

    public function testDashInteractiveEchoRoundTrip(): void
    {
        $this->requirePtySyscalls();
        $this->requireBinary(self::DASH_PATH, 'dash');

        $output = $this->runShellRoundTrip(
            [self::DASH_PATH, '-i'],
            "echo hi\nexit\n",
        );

        $this->assertStringContainsString(
            'hi',
            $output,
            'dash -i must echo "hi" within the 5s wallclock budget',
        );
    }
}
