<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GifView\Cli;

/**
 * Polarity pins for the gd preflight (Cli::main's `!extension_loaded('gd')`
 * gate) — REACH-1 m1: the guard shipped without a pin that can see it, so
 * deleting the whole block stayed green on every gd-present host.
 *
 * Three layers, because no single host shape proves both branches:
 *  - STRUCTURAL: the gate exists, in negated polarity, ahead of the viewport
 *    probe and the intake, and its body is fail-closed (keyed message +
 *    exit 1). This reddens on any host under a neuter mutation.
 *  - PRESENT polarity (behavioural): with gd loaded, a failing decode is
 *    relayed as cannot_play and the need_gd refusal NEVER appears.
 *  - ABSENT polarity (child): the real bin under `php -n` — measured on this
 *    fleet's devbox and CI runners, `-n` drops gd (it ships as a shared
 *    module), so the child takes the refusal branch for real. Hosts with gd
 *    compiled in disclose the skip; the structural layer keeps them honest.
 */
final class GdGuardPolarityTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-gifview';

    /** Locale-independent ASCII token: every one of the 16 need_gd translations carries "php-gd". */
    private const NEED_GD_MARKER = 'php-gd';

    public function testTheGdGateFailsClosedAheadOfTheViewportProbeAndTheIntake(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Cli.php');

        $gate = strpos($src, "if (!\\extension_loaded('gd')) {");
        $this->assertNotSame(
            false,
            $gate,
            'the negated gd preflight is gone — gd-less hosts would fatal inside the GIF walk',
        );
        $viewport = strpos($src, 'self::viewportCells();');
        $intake = strpos($src, '$decode($path, $cellsW, $cellsH)');
        $this->assertNotSame(false, $viewport);
        $this->assertNotSame(false, $intake);
        $this->assertLessThan($viewport, $gate, 'the gd gate must precede the viewport probe');
        $this->assertLessThan($intake, $gate, 'the gd gate must precede any file read');

        // Fail-closed body: keyed localized refusal on stderr + exit 1.
        $body = substr($src, $gate, strpos($src, "\n        }\n", $gate) - $gate);
        $this->assertStringContainsString("Lang::t('cli.need_gd')", $body);
        $this->assertStringContainsString('fwrite($stderr', $body);
        $this->assertStringContainsString('return 1;', $body);
    }

    public function testWithGdPresentTheRefusalStaysSilentAndTheIntakeRuns(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('present-polarity branch needs the host to carry gd');
        }

        $intakeRan = false;
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $decode = static function () use (&$intakeRan): array {
            $intakeRan = true;

            throw new \RuntimeException('sugar-animation: no such file: /tmp/none.gif');
        };

        $exit = Cli::main(
            ['candy-gifview', '/tmp/none.gif'],
            $stdout,
            $stderr,
            static fn (): int => 0,
            null,
            $decode,
        );

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertTrue($intakeRan, 'gd present: the gate must let the run reach the intake');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('could not play', $err);
        $this->assertStringNotContainsString(
            self::NEED_GD_MARKER,
            $err,
            'the gd refusal must not fire when gd is loaded',
        );
    }

    public function testTheRealBinaryRefusesWithoutGdUnderANoIniChild(): void
    {
        if (!\function_exists('proc_open') || !is_executable('/usr/bin/timeout')) {
            $this->markTestSkipped('proc_open() or the timeout(1) wrapper is unavailable');
        }

        // Prove the lever exists HERE before judging the child: if even
        // -n reports gd (statically compiled), this host cannot exercise
        // the absent branch behaviourally — disclose, never fake-green.
        $probe = $this->probeOut(['timeout', '10', \PHP_BINARY, '-n', '-r',
            'echo extension_loaded("gd") ? "present" : "absent";',
        ]);
        if ($probe !== 'absent') {
            $this->markTestSkipped(
                'gd survives `php -n` on this host (compiled in); the structural pin is the guard',
            );
        }

        $run = $this->runWithStderrCapture(
            ['timeout', '10', \PHP_BINARY, '-n', '-d', 'extension=phar', realpath(self::BIN), '/tmp/definitely-not-here.gif'],
        );

        $this->assertSame(1, $run[0], 'gd-less bin must exit 1');
        $this->assertStringContainsString(
            self::NEED_GD_MARKER,
            $run[1],
            'the real bin did not take the preflight refusal without gd — the gate is gone',
        );
        $this->assertStringNotContainsString(
            'could not play',
            $run[1],
            'a gd-less run must refuse at the preflight, not reach the decode relay',
        );
    }

    /** @param list<string> $argv */
    private function probeOut(array $argv): string
    {
        [$code, $err, $out] = $this->runWithStderrCapture($argv);
        $this->assertSame(0, $code, 'probe child failed: ' . $err);

        return trim($out);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string} exit code, captured stderr, captured stdout
     */
    private function runWithStderrCapture(array $argv): array
    {
        $errLog = tempnam(sys_get_temp_dir(), 'gv_gd_err_');
        $outLog = tempnam(sys_get_temp_dir(), 'gv_gd_out_');
        $this->assertIsString($errLog);
        $this->assertIsString($outLog);

        $process = proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outLog, 'w'],
                2 => ['file', $errLog, 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($process, 'could not start the child: ' . implode(' ', $argv));
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $code = proc_close($process);

        $err = (string) file_get_contents($errLog);
        $out = (string) file_get_contents($outLog);
        @unlink($errLog);
        @unlink($outLog);

        return [$code, $err, $out];
    }
}
