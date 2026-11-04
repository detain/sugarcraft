<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;

/**
 * E3 (docs-fix-1023) MINOR-13: `--expert 10 10` used to silently start the
 * PRESET and drop the positionals. The binary must refuse the combination
 * loudly. Each case is a bounded, pipe-stdin child — no ctty involvement —
 * and the refusals exit BEFORE any Program starts, so the runs are cheap and
 * deterministic.
 */
final class BinArgvTest extends TestCase
{
    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    /** @param array<string,string> $extraEnv */
    private static function runBinary(array $args, array $extraEnv = []): array
    {
        $bin = realpath(__DIR__ . '/../bin/candy-minesweeper');
        self::assertIsString($bin, 'sanity: binary exists');

        $proc = proc_open(
            [PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            // HOME without stats env: the refusal path never reads either, but
            // pinning the env keeps the child hermetic against the dev HOME.
            ['HOME' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH')] + $extraEnv,
        );
        self::assertIsResource($proc);

        // The child must not wait on us: close stdin immediately.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc);
            self::fail('binary did not exit within 10s for args: ' . implode(' ', $args));
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testPresetPlusPositionalIsRefused(): void
    {
        [$code, , $err] = self::runBinary(['--expert', '10', '10']);

        $this->assertSame(1, $code, 'the silent-drop combination must exit non-zero');
        $this->assertStringContainsString('mutually exclusive', $err);
    }

    public function testMediumPlusPositionalIsRefusedToo(): void
    {
        [$code, , $err] = self::runBinary(['--medium', '5']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('mutually exclusive', $err);
    }

    public function testTwoPresetsAreRefusedNotLastWins(): void
    {
        [$code, , $err] = self::runBinary(['--easy', '--expert']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('more than one preset flag', $err);
    }

    public function testUnknownOptionStillRefused(): void
    {
        [$code, , $err] = self::runBinary(['--frobnicate']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown option', $err);
    }

    public function testUsageNamesBothInvocationShapes(): void
    {
        [$code, , $err] = self::runBinary(['--nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('candy-minesweeper [width] [height] [mines]', $err);
        $this->assertStringContainsString('--easy | --standard | --medium | --expert', $err);
    }

    public function testCustomBoardWithBadDimensionsStillRefused(): void
    {
        // Guard against the new early-refusal shadowing the existing
        // CustomDifficulty validation: 3×3 boards cannot carry the 3×3
        // safe-area contract, and must still exit 1 loudly.
        [$code, , $err] = self::runBinary(['3', '3', '1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('candy-minesweeper:', $err);
    }

    public function testNonIntegerWidthIsRefusedNotSilentlyCast(): void
    {
        // M08: the (int) cast booted '10x10' as width 10; the parse now
        // requires plain digit strings.
        [$code, , $err] = self::runBinary(['10x10', '10', '12']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("width must be a plain integer", $err);
    }

    public function testFractionalAndWordHeightsAreRefused(): void
    {
        foreach (['9.5', '10abc', ' 10'] as $junk) {
            [$code, , $err] = self::runBinary(['10', $junk, '12']);
            $this->assertSame(1, $code, 'junk height ' . $junk . ' must not boot');
            $this->assertStringContainsString('height must be a plain integer', $err);
        }
    }

    public function testFourthPositionalIsRefusedNotIgnored(): void
    {
        [$code, , $err] = self::runBinary(['10', '10', '12', '99']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("unexpected extra argument '99'", $err);
    }

    public function testUsageTeachesColumnRowOrderAndSlotPath(): void
    {
        // M19/m03: the error surface itself must say which axis is which,
        // name the save slot, and teach the stats env.
        [$code, , $err] = self::runBinary(['nope-not-a-number-at-all', '10']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('columns (width) then rows (height)', $err);
        $this->assertStringContainsString('savegame.json', $err);
        $this->assertStringContainsString('CANDY_MINESWEEPER_STATS', $err);
    }

    public function testUnknownOptionEchoIsControlByteSafe(): void
    {
        // m21: an argv fragment carrying ESC reformatting the very error that
        // reports it is an injection of the terminal, not a message.
        [$code, , $err] = self::runBinary(["--\x1b[31mred\x07"]);
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString("\x1b", $err, 'raw ESC must never reach stderr');
        $this->assertStringNotContainsString("\x07", $err);
        $this->assertStringContainsString('unknown option --?', $err, 'control bytes become ? marks');

        // Positive control that the child CAN emit raw bytes when it means to
        // (locale-independent): --version rides Version::line() verbatim.
        [$vcode, $vout] = self::runBinary(['--version']);
        $this->assertSame(0, $vcode);
        $this->assertNotEmpty($vout);
    }

    public function testLegacyEnvRemedyNamesTheVarsOwnReplacement(): void
    {
        // m22: every pre-rename var used to be told to set _STATS.
        [$code, , $err] = self::runBinary(['--version'], ['CANDY_MINES_DEBUG' => '1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('CANDY_MINES_DEBUG is deprecated', $err);
        $this->assertStringContainsString('set CANDY_MINESWEEPER_DEBUG instead', $err);
        $this->assertStringNotContainsString('CANDY_MINESWEEPER_STATS instead', $err);
    }
}
