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
    private static function runBinary(array $args): array
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
            ['HOME' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH')],
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
}
