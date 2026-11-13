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
     * @param list<string> $args
     * @return array{0:int, 1:string, 2:string} exit, stdout, stderr
     */
    private static function spawn(array $args): array
    {
        $bin  = realpath(__DIR__ . '/../bin/candy-files');
        self::assertIsString($bin, 'the launcher must exist to be tested');
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(array_merge([PHP_BINARY, $bin], $args), $spec, $pipes, sys_get_temp_dir());
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }
}
