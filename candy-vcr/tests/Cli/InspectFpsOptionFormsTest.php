<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * F2 (docs-fix-1023 M4) — `inspect --frames` honours BOTH spellings of the
 * fps option, `--fps=<n>` and `--fps <n>`, like `render-tape` does, and
 * every value goes through the single {@see \SugarCraft\Vcr\Cli\FpsOption}
 * validation door: non-numeric, zero and missing values exit 2 at the
 * boundary instead of reaching the render pipeline.
 */
final class InspectFpsOptionFormsTest extends TestCase
{
    private string $tapePath;

    protected function setUp(): void
    {
        $this->tapePath = sys_get_temp_dir() . '/candy-vcr-fpsforms-' . bin2hex(random_bytes(4)) . '.tape';
        file_put_contents($this->tapePath, "Type \"x\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tapePath);
    }

    public function testEqualsFormIsAccepted(): void
    {
        [$exit, $out] = $this->fpsInspect('--fps=5');

        $this->assertSame(0, $exit, $out);
        $this->assertMatchesRegularExpression('/frames: \d+\s+unique: \d+\s+deduped: \d+/', $out);
    }

    public function testSpaceFormIsAccepted(): void
    {
        [$exit, $out] = $this->fpsInspect('--fps', '5');

        $this->assertSame(0, $exit, "space form must be accepted, got:\n{$out}");
        $this->assertMatchesRegularExpression('/frames: \d+\s+unique: \d+\s+deduped: \d+/', $out);
    }

    public function testSpaceFormValueIsNotMistakenForTheCassettePath(): void
    {
        // If the space-form value were left unconsumed it would land in the
        // positional slot and the real tape path (seen earlier) would win —
        // but a reordered argv proves consumption: put the value BEFORE the
        // path and the command must still find exactly one path.
        [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', '--fps', '5', $this->tapePath, '--frames']);

        $this->assertSame(0, $exit, $out);
        $this->assertStringNotContainsString('unknown', $out);
    }

    public function testBareSpaceFormWithoutValueExits2(): void
    {
        [$exit, $out] = $this->fpsInspect('--fps');

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('--fps requires a value', $out);
    }

    public function testEqualsFormWithoutValueExits2(): void
    {
        [$exit, $out] = $this->fpsInspect('--fps=');

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('--fps requires a value', $out);
    }

    public function testEqualsFormWithNextOptionAsOnlyArgumentTreatsValueMissing(): void
    {
        [$exit, $out] = $this->fpsInspect('--fps', '--frames');

        $this->assertSame(2, $exit, $out);
        $this->assertStringContainsString('--fps requires a value', $out);
    }

    public function testNonNumericValueExits2InBothForms(): void
    {
        foreach ([['--fps=abc'], ['--fps', 'abc']] as $argv) {
            [$exit, $out] = $this->fpsInspect(...$argv);

            $this->assertSame(2, $exit, $out);
            $this->assertStringContainsString('must be a positive number', $out);
        }
    }

    public function testZeroValueExits2InBothForms(): void
    {
        foreach ([['--fps=0'], ['--fps', '0']] as $argv) {
            [$exit, $out] = $this->fpsInspect(...$argv);

            $this->assertSame(2, $exit, $out);
            $this->assertStringContainsString('must be a positive', $out);
        }
    }

    public function testNegativeValueExits2InBothForms(): void
    {
        foreach ([['--fps=-1'], ['--fps', '-1']] as $argv) {
            [$exit, $out] = $this->fpsInspect(...$argv);

            $this->assertSame(2, $exit, $out);
            $this->assertStringContainsString('must be a positive', $out);
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    private function fpsInspect(string ...$fpsArgv): array
    {
        return $this->runCli(array_merge(
            ['candy-vcr', 'inspect', $this->tapePath, '--frames'],
            $fpsArgv,
        ));
    }

    /**
     * @param list<string> $argv
     * @return array{0:int,1:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        $combined = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);
        return [$exit, $combined];
    }
}
