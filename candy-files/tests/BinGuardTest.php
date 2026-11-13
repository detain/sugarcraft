<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Launcher-contract pins for `bin/candy-files` (CF-19, closes audit 027).
 * The TUI is a full-screen alt-buffer program: a piped stdout used to emit
 * escape-sequence soup and block on an unreadable stdin — an un-killable
 * launch (rc=137 observed by two independent lanes). The guard refuses that
 * shape at the door with one readable sentence, while --version/--help stay
 * piped-safe for packaging scripts and bug reports.
 *
 * These behaviors exist only as process exit codes and stderr prose, so the
 * real binary is spawned with real pipes — the same shape a cron job or a
 * distribution smoke test experiences.
 */
final class BinGuardTest extends TestCase
{
    public function testAPipedStdoutIsRefusedInsteadOfRenderingSoup(): void
    {
        [$exit, $out, $err] = self::spawn([]);

        // stdout is a pipe for proc_open here — exactly the headless shape
        // the launcher must refuse before the alt-screen program starts.
        self::assertSame(1, $exit);
        self::assertStringContainsString('terminal', $err);
        self::assertSame('', $out, 'no escape sequences may reach a piped stdout');
    }

    public function testTheDoorGuardRunsBeforeTheDirectoryProbe(): void
    {
        // A bogus path with a piped stdout is refused for being headless,
        // not for the path: the escape-spitter never boots at all.
        [$exit, $out, $err] = self::spawn(['/definitely/not/here-candy-files']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('terminal', $err);
        self::assertStringNotContainsString('does not exist', $err);
        self::assertSame('', $out);
    }

    public function testVersionFlagAnswersWhilePiped(): void
    {
        [$exit, $out] = self::spawn(['--version']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('candy-files', $out);
    }

    public function testHelpFlagAnswersWhilePiped(): void
    {
        [$exit, $out] = self::spawn(['--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage:', $out);
    }

    /**
     * CF-34 (closes 047): the cd-on-quit handshake must be discoverable
     * from the headless usage block, and it must never lie — with stdout
     * captured by the wrapper, the UI has nowhere to live unless /dev/tty
     * answers, so a session-less launch is refused at the door rather
     * than emitting the escape soup (or a path line nobody sees).
     */
    public function testUsageListsThePrintCdHandshake(): void
    {
        [$exit, $out] = self::spawn(['--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('--print-cd', $out);
    }

    public function testPrintCdRefusesWithoutAControllingTerminal(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/setsid')) {
            self::markTestSkipped('the session-less launch probe needs Linux setsid');
        }

        $bin = realpath(__DIR__ . '/../bin/candy-files');
        self::assertIsString($bin);
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // setsid strips the controlling terminal, so /dev/tty is provably
        // unreachable — the exact shape a cron job or a CI pipe presents.
        $proc = proc_open(['/usr/bin/setsid', PHP_BINARY, $bin, '--print-cd'], $spec, $pipes, sys_get_temp_dir());
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        self::assertSame(1, $exit);
        self::assertStringContainsString('controlling terminal', $err);
        self::assertSame('', $out, 'no path line may escape a refused launch');
    }

    /**
     * CF-16 (closes 060): a German terminal must be refused AND served in
     * German — locale detection hoisted to right-after-autoload means the
     * usage block rides the catalogue. --help stays piped-safe, so the
     * whole CLI leg is observable from a headless subprocess.
     */
    public function testHelpAnswersInGermanWhenTheEnvironmentIsGerman(): void
    {
        // detect() walks LC_ALL → LC_MESSAGES → LANG; the developer shell
        // exports an en_US LC_MESSAGES, so the child's whole chain is
        // pinned — otherwise LANG alone is correctly outranked.
        [$exit, $out] = self::spawn(['--help'], ['LC_ALL' => '', 'LC_MESSAGES' => '', 'LANG' => 'de_DE.UTF-8']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Verwendung:', $out);
        self::assertStringNotContainsString('Usage:', $out);
    }

    /**
     * @param list<string> $args
     * @param list<string> $extraEnv appended to the inherited environment
     * @return array{0:int, 1:string, 2:string} exit, stdout, stderr
     */
    private static function spawn(array $args, array $extraEnv = []): array
    {
        $bin  = realpath(__DIR__ . '/../bin/candy-files');
        self::assertIsString($bin, 'the launcher must exist to be tested');
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env  = $extraEnv === [] ? null : array_merge(getenv(), $extraEnv);
        $proc = proc_open(array_merge([PHP_BINARY, $bin], $args), $spec, $pipes, sys_get_temp_dir(), $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
