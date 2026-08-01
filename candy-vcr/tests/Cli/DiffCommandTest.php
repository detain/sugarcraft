<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\DiffCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * Tests for DiffCommand CLI.
 * Covers collectDiffs branches including different header fields and event comparisons.
 */
final class DiffCommandTest extends TestCase
{
    private function writeCassette(string $path, array $events, int $version = 1, int $cols = 80, int $rows = 24, string $runtime = 'test'): string
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: $version,
                createdAt: '2026-05-07T10:00:00Z',
                cols: $cols,
                rows: $rows,
                runtime: $runtime,
            ),
            $events,
        );
        (new JsonlFormat())->write($cassette, $path);
        return $path;
    }

    public function testDiffWrongArityZero(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new DiffCommand())->run([], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage:', $err);
    }

    public function testDiffWrongArityOne(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv-diff-');
        $this->writeCassette($path, []);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$path], $stdout, $stderr);
            rewind($stderr);
            $err = (string) stream_get_contents($stderr);

            $this->assertSame(2, $exit);
            $this->assertStringContainsString('usage:', $err);
        } finally {
            @unlink($path);
        }
    }

    public function testDiffIdentical(): void
    {
        $events = [
            new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24]),
            new Event(t: 0.5, kind: EventKind::Quit, payload: []),
        ];
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('identical', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffVersionMismatch(): void
    {
        $events = [new Event(t: 0.0, kind: EventKind::Quit, payload: [])];
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 1);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 2);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('header.v:', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffDimensionMismatch(): void
    {
        $events = [new Event(t: 0.0, kind: EventKind::Quit, payload: [])];
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 1, 80, 24);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 1, 120, 40);
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('header dimensions:', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffRuntimeMismatch(): void
    {
        $events = [new Event(t: 0.0, kind: EventKind::Quit, payload: [])];
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 1, 80, 24, 'runtime-a');
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $events, 1, 80, 24, 'runtime-b');
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('header.runtime:', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffEventCountMismatch(): void
    {
        $a = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])]
        );
        $b = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ]
        );
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('event count:', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffEventMissingInA(): void
    {
        $a = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ]
        );
        $b = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'b']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ]
        );
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('missing in A', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffEventMissingInB(): void
    {
        $a = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.1, kind: EventKind::Output, payload: ['b' => 'b']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ]
        );
        $b = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.5, kind: EventKind::Quit, payload: []),
            ]
        );
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('missing in B', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffEventKindMismatch(): void
    {
        $a = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x'])]
        );
        $b = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [new Event(t: 0.0, kind: EventKind::Resize, payload: ['cols' => 80, 'rows' => 24])]
        );
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('kind output != resize', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffEventPayloadMismatch(): void
    {
        $a = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a'])]
        );
        $b = $this->writeCassette(
            tempnam(sys_get_temp_dir(), 'cv-diff-'),
            [new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'b'])]
        );
        try {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('payload differs', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffNonExistentFile(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $exit = (new DiffCommand())->run(['/no/such/a.cas', '/no/such/b.cas'], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $err);
    }

    public function testSummary(): void
    {
        $cmd = new DiffCommand();
        $this->assertSame('Compare two cassettes event-by-event', $cmd->summary());
    }
}
