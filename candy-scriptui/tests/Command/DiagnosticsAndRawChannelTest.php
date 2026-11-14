<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Tests\Support\AmbientIsolation;

/**
 * End-to-end shape of the two channels: DATA (the rendered result, raw —
 * never passed through the console formatter, so <…> looking text in user
 * values survives byte-exact) versus DIAGNOSTICS (refusals on STDERR, so
 * an eval'd `completion` stream or a scripted `confirm` run can never
 * consume an error message as if it were data).
 */
final class DiagnosticsAndRawChannelTest extends TestCase
{
    use AmbientIsolation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateAmbient();
    }

    protected function tearDown(): void
    {
        $this->releaseAmbient();
        parent::tearDown();
    }
    private const BIN = __DIR__ . '/../../bin/candy-scriptui';

    /** @param array<string,string> $env @param array{0:int,1:string,2:string} $_ */
    private function runBinChild(array $args, string $stdin = '', array $env = []): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $fullEnv = ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()] + $env;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, ...$args],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            $fullEnv,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return [$status, (string) $stdout, (string) $stderr];
    }

    public function testDataChannelPreservesAngleBracketTextByteExactly(): void
    {
        // Before the OUTPUT_RAW sweep the formatter ATE these tags:
        // stdout read "hello X tail" (measured at the review).
        [$status, $stdout, $stderr] = $this->runBinChild(
            ['style', '--bold', 'hello <info>X</info> <b>y</b> tail'],
        );
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('<info>X</info>', $stdout);
        self::assertStringContainsString('<b>y</b>', $stdout);
    }

    public function testPlainStyleStillCarriesItsOwnSgrIntoRawMode(): void
    {
        [$status, $stdout] = $this->runBinChild(['style', '--bold', 'hi'], env: ['FORCE_COLOR' => '3']);
        self::assertSame(0, $status);
        self::assertStringContainsString("\x1b[1mhi\x1b[0m", $stdout);
    }

    public function testConfirmRefusalLandsOnStderrNotStdout(): void
    {
        [$status, $stdout, $stderr] = $this->runBinChild(['confirm', '--default=maybe'], stdin: "y\n");
        self::assertSame(1, $status);
        self::assertSame('', $stdout, 'a refusal must never pollute the answer channel');
        self::assertStringContainsString('maybe', $stderr);
    }

    public function testCompletionRefusalKeepsTheEvalStreamClean(): void
    {
        $forged = "bash\ncomplete -p candy-scriptui #injected";
        [$status, $stdout, $stderr] = $this->runBinChild(['completion', '--shell=' . $forged]);
        self::assertSame(1, $status);
        self::assertSame('', $stdout, 'the eval stream must stay empty when the shell is refused');
        self::assertStringContainsString('Allowed: bash|zsh|fish', $stderr);
        self::assertStringNotContainsString('injected', $stdout . $stderr);
    }

    public function testMissingReadFailureIsAnnouncedOnStderr(): void
    {
        $path = '/definitely/not/here.phpunit-' . getmypid();
        [$status, $stdout, $stderr] = $this->runBinChild(['table', '--file', $path]);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString($path, $stderr);
    }

    public function testCursorModeRefusalNamesEveryAllowedMode(): void
    {
        [$status, $stdout, $stderr] = $this->runBinChild(['input', '--cursor-mode=bogus']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout, 'the refusal must not touch the DATA stream');
        self::assertStringContainsString('Allowed: blink|static|hidden', $stderr);
    }

    public function testFormatTypeRefusalNamesEveryAllowedType(): void
    {
        [$status, $stdout, $stderr] = $this->runBinChild(['format', 'x', '--type=bogus']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Allowed: markdown|code|template|emoji', $stderr);
    }

    public function testTemplateVanishedPlaceholderIsAnnouncedOnStderr(): void
    {
        [$status, $stdout, $stderr] = $this->runBinChild(
            ['format', '--type=template'],
            'value=[{{SC_B11_MISSING}}]',
        );
        self::assertSame(0, $status, 'the announcement is a note, not a failure');
        self::assertStringContainsString('value=[]', $stdout);
        self::assertStringContainsString('SC_B11_MISSING', $stderr,
            'a placeholder that expanded to nothing must be named, never vanish silently');
    }

    public function testUnknownStyleBorderRefusesWithAllowedList(): void
    {
        [$status, $stdout, $stderr] = $this->runBinChild(['style', '--border=fancy', 'hi']);
        self::assertNotSame(0, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Allowed: normal|rounded|thick|double|block|hidden', $stderr);
    }
}
