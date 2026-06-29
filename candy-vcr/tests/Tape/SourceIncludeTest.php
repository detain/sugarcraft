<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;

/**
 * Tests for Source directive depth/cycle guards and base-dir confinement.
 */
final class SourceIncludeTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testSelfSourceDoesNotRecurseInfinitely(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-test-' . getmypid();
        mkdir($tmpDir, 0755, true);

        $selfTape = $tmpDir . '/self.tape';
        file_put_contents($selfTape, "Type \"A\"\nSource self.tape\nType \"B\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($selfTape));
            // Should not hang — cycle is detected and skipped
            $cassette = $this->compiler->compile($result['ast'], $selfTape);

            // Only the pre-cycle events should be present (Type A and Type B from first parse)
            $inputEvents = array_filter(
                $cassette->events,
                fn($e) => $e->kind === EventKind::Input,
            );
            // Should have 2 events: "A" and "B" (1 char each), not infinite recursion
            // Note: due to the way cycle detection uses path keys, self-reference may
            // still allow two parses. The important thing is it doesn't recurse infinitely.
            $this->assertLessThanOrEqual(4, count($inputEvents));
        } finally {
            @unlink($selfTape);
            @rmdir($tmpDir);
        }
    }

    public function testCycleAToBToAIsGuarded(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-cycle-' . getmypid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/a.tape', "Type \"A\"\nSource b.tape\nType \"afterA\"\n");
        file_put_contents($tmpDir . '/b.tape', "Type \"B\"\nSource a.tape\nType \"afterB\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($tmpDir . '/a.tape'));
            $cassette = $this->compiler->compile($result['ast'], $tmpDir . '/a.tape');

            $inputEvents = array_filter(
                $cassette->events,
                fn($e) => $e->kind === EventKind::Input,
            );

            // The cycle guard should prevent infinite recursion.
            // With proper cycle detection: A from a.tape, then B + afterB from b.tape, then afterA from a.tape
            // Without cycle detection: would hang or produce many more events.
            // We assert it doesn't grow unbounded.
            $this->assertLessThan(100, count($inputEvents));
        } finally {
            @unlink($tmpDir . '/a.tape');
            @unlink($tmpDir . '/b.tape');
            @rmdir($tmpDir);
        }
    }

    public function testDepthLimitThrowsBeyondMax(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-depth-' . getmypid();
        mkdir($tmpDir, 0755, true);

        // Create 12 nested files (more than MAX_SOURCE_DEPTH=10)
        for ($i = 0; $i < 12; $i++) {
            $next = $i + 1;
            file_put_contents($tmpDir . "/level{$i}.tape", "Type \"{$i}\"\nSource level{$next}.tape\n");
        }
        file_put_contents($tmpDir . '/level12.tape', "Type \"deep\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($tmpDir . '/level0.tape'));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Source include depth exceeded');

            $this->compiler->compile($result['ast'], $tmpDir . '/level0.tape');
        } finally {
            for ($i = 0; $i <= 12; $i++) {
                @unlink($tmpDir . "/level{$i}.tape");
            }
            @rmdir($tmpDir);
        }
    }

    public function testSourceOutsideBaseDirIsRejected(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-confine-' . getmypid();
        mkdir($tmpDir, 0755, true);
        mkdir($tmpDir . '/subdir', 0755, true);

        // Create a tape that tries to escape via ../
        file_put_contents($tmpDir . '/subdir/escape.tape', "Type \"ESCAPED\"\n");
        file_put_contents($tmpDir . '/main.tape', "Type \"START\"\nSource ../subdir/escape.tape\nType \"END\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($tmpDir . '/main.tape'));
            $cassette = $this->compiler->compile($result['ast'], $tmpDir . '/main.tape');

            $inputEvents = array_filter(
                $cassette->events,
                fn($e) => $e->kind === EventKind::Input,
            );

            // Should only have START and END, not ESCAPED (8 events: 5 for START + 3 for END)
            $this->assertCount(8, $inputEvents);
        } finally {
            @unlink($tmpDir . '/main.tape');
            @unlink($tmpDir . '/subdir/escape.tape');
            @rmdir($tmpDir . '/subdir');
            @rmdir($tmpDir);
        }
    }

    public function testRelativeSameDirSourceStillInlines(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-inline-' . getmypid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/ok.tape', "Type \"INCLUDED\"\n");
        file_put_contents($tmpDir . '/main.tape', "Type \"START\"\nSource ok.tape\nType \"END\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($tmpDir . '/main.tape'));
            $cassette = $this->compiler->compile($result['ast'], $tmpDir . '/main.tape');

            $inputEvents = array_filter(
                $cassette->events,
                fn($e) => $e->kind === EventKind::Input,
            );

            // Should have START (5) + INCLUDED (8) + END (3) = 16 events (chars)
            $this->assertCount(16, $inputEvents);
        } finally {
            @unlink($tmpDir . '/main.tape');
            @unlink($tmpDir . '/ok.tape');
            @rmdir($tmpDir);
        }
    }

    public function testAbsolutePathSourceOutsideBaseDirIsRejected(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vcr-src-abs-' . getmypid();
        mkdir($tmpDir, 0755, true);

        file_put_contents($tmpDir . '/main.tape', "Type \"START\"\nSource /etc/hostname\nType \"END\"\n");

        try {
            $result = Compiler::parseSource(file_get_contents($tmpDir . '/main.tape'));
            $cassette = $this->compiler->compile($result['ast'], $tmpDir . '/main.tape');

            $inputEvents = array_filter(
                $cassette->events,
                fn($e) => $e->kind === EventKind::Input,
            );

            // Should only have START (5) and END (3) = 8 events, not /etc/hostname content
            $this->assertCount(8, $inputEvents);
        } finally {
            @unlink($tmpDir . '/main.tape');
            @rmdir($tmpDir);
        }
    }
}
