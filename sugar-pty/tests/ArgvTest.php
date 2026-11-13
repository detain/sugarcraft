<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Argv;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Spawn;

/**
 * PTY-08: spawn-failure messages name the program and never the arguments.
 * Secrets ride argv; every consumer that logs throwables would log them.
 */
final class ArgvTest extends TestCase
{
    public function testDescribeNamesTheProgramAndCountsTheWithheldArgs(): void
    {
        $line = Argv::describe(['/usr/bin/curl', '--header', 'Authorization: Bearer sekrit', 'https://x']);

        self::assertStringContainsString('/usr/bin/curl', $line);
        self::assertStringNotContainsString('sekrit', $line);
        self::assertStringNotContainsString('Authorization', $line);
        self::assertStringContainsString('+3 args redacted', $line);
        self::assertStringContainsString('SUGAR_PTY_DEBUG_ARGV', $line, 'the message must tell the reader how to opt in');
    }

    public function testDescribeOfALoneProgramNeedsNoRedactionNote(): void
    {
        self::assertSame('"/bin/true"', Argv::describe(['/bin/true']));
    }

    public function testDebugOptInRestoresTheFullLine(): void
    {
        $previous = \getenv('SUGAR_PTY_DEBUG_ARGV');
        \putenv('SUGAR_PTY_DEBUG_ARGV=1');
        try {
            self::assertSame(
                '/usr/bin/curl --header Authorization: Bearer sekrit https://x',
                Argv::describe(['/usr/bin/curl', '--header', 'Authorization: Bearer sekrit', 'https://x']),
            );
            self::assertSame(
                'app.exe -pPassWord',
                Argv::describeWide('app.exe', 'app.exe -pPassWord'),
            );
        } finally {
            if ($previous === false) {
                \putenv('SUGAR_PTY_DEBUG_ARGV');
            } else {
                \putenv('SUGAR_PTY_DEBUG_ARGV=' . $previous);
            }
        }
    }

    public function testDescribeWideDropsTheJoinedLineButKeepsTheApplicationName(): void
    {
        $line = Argv::describeWide('C:\\tools\\agent.exe', 'C:\\tools\\agent.exe --token=t0kenpl3ase');

        self::assertStringContainsString('C:\\tools\\agent.exe', $line);
        self::assertStringNotContainsString('t0kenpl3ase', $line);
        $anon = Argv::describeWide('', 'cmd.exe /c net user attacker P@ss');
        self::assertStringNotContainsString('P@ss', $anon);
        self::assertStringContainsString('33 characters', $anon);
    }

    /**
     * Wiring witness: the real Spawn failure path renders the redacted
     * description, not the argv join. Hosts whose proc_open defers the ENOENT
     * to the child get the same property asserted on the no_pid message.
     */
    public function testSpawnFailureMessageCarriesNoArguments(): void
    {
        $pty = Pty::open();
        try {
            try {
                $child = Spawn::proc($pty->master, ['/no/such/binary-pty08', '--sekrit=do-not-log-me']);
                $child->wait();
                self::assertNotSame(0, $child->exitCode() ?? 0, 'a deferred ENOENT must still exit non-zero');

                return;
            } catch (PtyException $e) {
                $message = $e->getMessage();
            }
        } finally {
            $pty->close();
        }

        self::assertStringContainsString('/no/such/binary-pty08', $message);
        self::assertStringNotContainsString('do-not-log-me', $message);
    }
}
