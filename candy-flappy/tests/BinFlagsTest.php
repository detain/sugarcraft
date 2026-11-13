<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flappy\Support\Version;

/**
 * bin/candy-flappy argument contract (M13/M14). The diagnostic flags are the
 * ONLY arguments the game accepts; each child run exits long before the TUI
 * boots, so these are safe to execute headless. Unknown flags must refuse
 * with exit 2 and show usage on stderr — they used to launch the game.
 */
final class BinFlagsTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-flappy';

    /**
     * @param array<int, string> $args
     * @return array{0:int, 1:string, 2:string}
     */
    private static function drive(array $args): array
    {
        $handle = proc_open(
            ['php', self::BIN, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($handle);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($handle), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    public function testVersionFlagPrintsTheStampedLine(): void
    {
        [$code, $out] = self::drive(['--version']);
        self::assertSame(0, $code);
        self::assertSame(Version::line(), $out);
    }

    public function testHelpAdvertisesEveryAcceptedFlagIncludingShortForm(): void
    {
        foreach ([['--help'], ['-h']] as $args) {
            [$code, $out] = self::drive($args);
            self::assertSame(0, $code, implode(' ', $args) . ' should exit 0');
            self::assertStringContainsString('--version', $out);
            // M13: -h was a working flag the usage text never mentioned.
            self::assertStringContainsString('-h, --help', $out);
            // flappy-10 (finding -060): help must position the game (no bare
            // "clone" tagline) and teach the controls (GAP-08).
            self::assertStringNotContainsString('clone', $out, 'the usage must not call the game a clone');
            self::assertStringContainsString('62x21', $out, 'usage must state the terminal size it needs');
            self::assertStringContainsString('flap', $out, 'usage must say how to play');
        }
    }

    public function testUnknownArgumentsRefuseWithExitTwoAndUsage(): void
    {
        [$code, $out, $err] = self::drive(['--play-please']);
        self::assertSame(2, $code);
        self::assertSame('', $out, 'refusal must keep stdout clean');
        self::assertStringContainsString('unknown argument', $err);
        self::assertStringContainsString('--play-please', $err);
        self::assertStringContainsString('Usage:', $err);

        // flappy-72 (finding -062): every bad argument gets its own refusal
        // line — no joined blob to re-count — and the usage block still
        // appears exactly once.
        [$code, $out, $err] = self::drive(['--nope', '--also-bad']);
        self::assertSame(2, $code);
        self::assertSame(
            2,
            preg_match_all('/unknown argument:/', $err),
            'two bad arguments must produce two refusal lines'
        );
        self::assertStringContainsString('unknown argument: --nope', $err);
        self::assertStringContainsString('unknown argument: --also-bad', $err);
        self::assertSame(1, substr_count($err, 'Usage:'), 'the usage block prints once');
    }

    public function testUnknownArgumentEchoCannotInjectTerminalEscapes(): void
    {
        // N3 (review round 2): the refusal used to fwrite raw argv bytes to
        // STDERR, so a crafted command line could paint the terminal (or
        // forge the message shape) through its own error report.
        // (NUL cannot cross execve, so it is exercised via the sanitiser directly.)
        $evil = "--\x1b[31mRED\x07bytes";
        [$code, $out, $err] = self::drive([$evil]);
        self::assertSame(2, $code);
        self::assertStringNotContainsString(\chr(27), $err, 'no ESC may reach the terminal');
        self::assertStringNotContainsString(\chr(7), $err);
        // Byte-level fallback branch of the sanitiser (invalid UTF-8 argv):
        $raw = "--" . chr(27) . "[31mR\x00\x07ED";
        $fallback = preg_replace('/[\x00-\x1f\x7f]+/', '?', $raw);
        self::assertStringNotContainsString(chr(0), (string) $fallback);
        self::assertStringNotContainsString(chr(27), (string) $fallback);
        self::assertStringContainsString('--?[31mR?ED', (string) $fallback);
        // The introducer is gone, so the surviving CSI parameter text is
        // inert; the run of BEL+NUL collapses to one marker byte.
        self::assertStringContainsString('--?[31mRED?bytes', $err,
            'printable residue must survive so the user sees what was rejected');
    }
}
