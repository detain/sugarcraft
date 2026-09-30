<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\FpsOption;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * F2 (round 90): --fps used to admit any is_numeric value — "0" reached
 * FrameStream's 1.0/$fps mid-render and negative fps made the emit loop
 * yield frames forever. The door now parses argv into a trusted positive
 * finite float or aborts before a single frame is built.
 */
final class FpsOptionTest extends TestCase
{
    public function testAbsentOptionYieldsNullForTheCallerDefault(): void
    {
        $this->assertNull(FpsOption::parse(null));
        $this->assertNull(FpsOption::parse(''));
    }

    public function testPositiveNumbersPassTheDoor(): void
    {
        $this->assertSame(30.0, FpsOption::parse('30'));
        $this->assertSame(12.5, FpsOption::parse('12.5'));
        $this->assertSame(0.001, FpsOption::parse('0.001'));
        $this->assertSame(60.0, FpsOption::parse(60));
    }

    /** @return array<array{string|int|float}> */
    public static function rejectedValues(): array
    {
        return [
            ['0'],
            [0],
            [0.0],
            ['-1'],
            [-2.5],
            ['abc'],
            ['0e0'],
            [NAN],
            [INF],
            [-INF],
        ];
    }

    /** @dataProvider rejectedValues */
    public function testNonPositiveNonFiniteAndGarbageAreRejected(string|int|float $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive');
        FpsOption::parse($raw);
    }

    public function testRenderTapeDoorAbortsBeforeRenderingZeroFps(): void
    {
        [$exit, $out] = $this->runRenderTape('--fps', '0');

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('positive', $out);
        $this->assertStringNotContainsString('DivisionByZeroError', $out);
    }

    public function testRenderTapeDoorRejectsNegativeFps(): void
    {
        // Symfony Console parses a bare `-1` after a space as an option
        // token, so the negative value must ride the `=` form.
        [$exit, $out] = $this->runRenderTape('--fps=-1');

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('positive', $out);
    }

    public function testRenderTapeAcceptsFractionalFpsThroughTheDoor(): void
    {
        [$exit, $out] = $this->runRenderTape('--fps', '12.5');

        $this->assertSame(0, $exit, $out);
    }

    /** @return array{0:int,1:string} */
    private function runRenderTape(string ...$extra): array
    {
        $tape = tempnam(sys_get_temp_dir(), 'cv-fps-') . '.tape';
        file_put_contents($tape, "Type \"hi\"\nEnter\n");
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $this->assertNotFalse($stdout);
            $this->assertNotFalse($stderr);

            $exit = (new Application())->run(
                array_values(['candy-vcr', 'render-tape', $tape, '--dry-run', ...$extra]),
                $stdout,
                $stderr,
            );

            rewind($stdout);
            rewind($stderr);

            return [$exit, stream_get_contents($stdout) . stream_get_contents($stderr)];
        } finally {
            @unlink($tape);
        }
    }
}
