<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Compiler;

/**
 * mi9: `Set TypingSpeed`/`Set PlaybackSpeed` used to coerce any garbage
 * through (float), and (float) 'quick' === 0.0 — instant typing, instant
 * cadence, zero complaints. Off-roster values now warn (or throw under
 * strict) and fall back to the documented default.
 */
final class SetSpeedDoorTest extends TestCase
{
    /**
     * @return array<string, array{string, float}>
     */
    public static function acceptedTypingSpeeds(): array
    {
        return [
            'milliseconds suffix' => ['Set TypingSpeed 60ms', 60.0],
            'seconds suffix' => ['Set TypingSpeed 0.25s', 250.0],
            'bare number is milliseconds' => ['Set TypingSpeed 30', 30.0],
            'empty falls back silently to the documented default' => ['Set TypingSpeed ""', 50.0],
        ];
    }

    #[DataProvider('acceptedTypingSpeeds')]
    public function testAcceptedTypingSpeedsCompileWithoutWarning(string $line, float $expected): void
    {
        $compiler = new Compiler();
        $cassette = $compiler->compile(Compiler::parseSource($line)['ast'], '/test.tape');

        $this->assertSame([], $compiler->warnings());
        $this->assertSame($expected, $cassette->header->typingSpeed);
    }

    public function testGarbageTypingSpeedWarnsAndDefaults(): void
    {
        $compiler = new Compiler();
        $cassette = $compiler->compile(
            Compiler::parseSource("Set TypingSpeed quick\nType \"x\"")['ast'],
            '/test.tape',
        );

        $warnings = $compiler->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Unparseable TypingSpeed value', $warnings[0]);
        $this->assertStringContainsString('quick', $warnings[0]);
        $this->assertSame(50.0, $cassette->header->typingSpeed);
    }

    public function testGarbageTypingSpeedThrowsUnderStrictCompile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unparseable TypingSpeed value');
        (new Compiler())->compile(Compiler::parseSource('Set TypingSpeed quick')['ast'], '/test.tape', true);
    }

    public function testGarbagePlaybackSpeedWarnsAndFallsBackToRecordedTiming(): void
    {
        $compiler = new Compiler();
        $cassette = $compiler->compile(Compiler::parseSource('Set PlaybackSpeed double')['ast'], '/test.tape');

        $warnings = $compiler->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Unparseable PlaybackSpeed value', $warnings[0]);
        $this->assertNull($cassette->header->playbackSpeed);
    }

    public function testNegativePlaybackSpeedIsRefusedLikeGarbage(): void
    {
        $compiler = new Compiler();
        $cassette = $compiler->compile(Compiler::parseSource('Set PlaybackSpeed "-2"')['ast'], '/test.tape');

        $this->assertCount(1, $compiler->warnings());
        $this->assertNull($cassette->header->playbackSpeed);
    }

    public function testAcceptedPlaybackSpeedsCompileWithoutWarning(): void
    {
        $compiler = new Compiler();
        $fast = $compiler->compile(Compiler::parseSource('Set PlaybackSpeed 2')['ast'], '/test.tape');
        $this->assertSame(2.0, $fast->header->playbackSpeed);

        $compiler2 = new Compiler();
        $empty = $compiler2->compile(Compiler::parseSource('Set PlaybackSpeed ""')['ast'], '/test.tape');
        $this->assertSame([], $compiler2->warnings());
        $this->assertNull($empty->header->playbackSpeed);
    }

    public function testGarbagePlaybackSpeedThrowsUnderStrictCompile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unparseable PlaybackSpeed value');
        (new Compiler())->compile(Compiler::parseSource('Set PlaybackSpeed double')['ast'], '/test.tape', true);
    }
}
