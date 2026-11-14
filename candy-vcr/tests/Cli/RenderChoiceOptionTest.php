<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\ChoiceOption;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * A flagged choice takes a CLOSED value set. Before this door the render
 * commands coerced any misspelling to the default, so `--backend=imagickk`
 * silently rendered through gd and `--encoder=ffmpe` silently encoded
 * through ffmpeg — the run looked fine and was not the run asked for.
 * A refusal must name every accepted spelling.
 */
final class RenderChoiceOptionTest extends TestCase
{
    private const BACKENDS = ['gd', 'imagick'];
    private const ENCODERS = ['ffmpeg', 'php'];

    public function testKnownValuesPassThroughUnchanged(): void
    {
        foreach (self::BACKENDS as $value) {
            $this->assertSame($value, ChoiceOption::parse($value, '--backend', self::BACKENDS, 'gd'));
        }
        foreach (self::ENCODERS as $value) {
            $this->assertSame($value, ChoiceOption::parse($value, '--encoder', self::ENCODERS, 'ffmpeg'));
        }
    }

    public function testAbsentFlagsTakeTheDocumentedDefault(): void
    {
        foreach ([null, '', false, []] as $absent) {
            $this->assertSame('gd', ChoiceOption::parse($absent, '--backend', self::BACKENDS, 'gd'));
            $this->assertSame('ffmpeg', ChoiceOption::parse($absent, '--encoder', self::ENCODERS, 'ffmpeg'));
        }
    }

    public function testATypoIsRefusedAndNamesEveryAcceptedValue(): void
    {
        try {
            ChoiceOption::parse('imagickk', '--backend', self::BACKENDS, 'gd');
            $this->fail('a misspelling must not coerce to the default');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('--backend', $message);
            $this->assertStringContainsString('must be one of gd, imagick', $message);
            $this->assertStringContainsString('got "imagickk"', $message);
            // The refusal is re-labelled by the CLI catch; no tool prefix.
            $this->assertStringNotContainsString('candy-vcr', $message);
        }
    }

    public function testAWrongTypeIsRefusedWithItsDebugType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--encoder must be one of ffmpeg, php, got int');
        ChoiceOption::parse(7, '--encoder', self::ENCODERS, 'ffmpeg');
    }

    public function testRenderTapeRefusesAnUnknownBackendBeforeDoingAnyWork(): void
    {
        $tape = $this->choiceTape("Type \"hi\"\nEnter\n");
        [$stdout, $stderr] = $this->openStreams();

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', $tape, '--dry-run', '--backend=imagickk'],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('candy-vcr render-tape:', $out);
        $this->assertStringContainsString('--backend must be one of gd, imagick', $out);
        $this->assertStringNotContainsString('_header', $out, 'the refusal must short-circuit the dry-run plan');
        @unlink($tape);
    }

    public function testRenderTapeRefusesAnUnknownEncoder(): void
    {
        $tape = $this->choiceTape("Type \"hi\"\nEnter\n");
        [$stdout, $stderr] = $this->openStreams();

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', $tape, '--dry-run', '--encoder=ffmpe'],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--encoder must be one of ffmpeg, php', $out);
        @unlink($tape);
    }

    public function testRenderTapeStillAcceptsTheDocumentedDefaults(): void
    {
        $tape = $this->choiceTape("Type \"hi\"\nEnter\n");
        [$stdout, $stderr] = $this->openStreams();

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', $tape, '--dry-run', '--backend=gd', '--encoder=php'],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(0, $exit, 'known values must pass the door: ' . $out);
        $this->assertStringContainsString('_header', $out);
        @unlink($tape);
    }

    public function testRenderBatchRefusesAnUnknownEncoderWithoutTouchingTheDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/candy-vcr-choice-' . bin2hex(random_bytes(4));
        $this->assertNotFalse(mkdir($dir, 0o700, true));
        file_put_contents($dir . '/one.tape', "Type \"hi\"\nEnter\n");
        [$stdout, $stderr] = $this->openStreams();

        $exit = (new Application())->run(
            ['candy-vcr', 'render-batch', $dir, '--encoder=ffmpe'],
            $stdout,
            $stderr,
        );

        rewind($stdout);
        $out = (string) stream_get_contents($stdout);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--encoder must be one of ffmpeg, php', $out);
        $this->assertStringNotContainsString('Rendering 1 tape(s)', $out, 'the door runs before the batch starts');
        @unlink($dir . '/one.tape');
        @rmdir($dir);
    }

    /** @return array{0: resource, 1: resource} */
    private function openStreams(): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);
        return [$stdout, $stderr];
    }

    private function choiceTape(string $contents): string
    {
        $path = sys_get_temp_dir() . '/candy-vcr-choice-' . bin2hex(random_bytes(4)) . '.tape';
        file_put_contents($path, $contents);
        return $path;
    }
}
