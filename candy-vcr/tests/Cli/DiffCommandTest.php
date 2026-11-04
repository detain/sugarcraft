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
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Tests for DiffCommand CLI.
 * Covers collectDiffs branches including different header fields and event comparisons.
 */
final class DiffCommandTest extends TestCase
{
    /**
     * @param list<Event> $events
     */
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
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
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
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
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

    /**
     * @param list<Event> $events
     */
    private function writeHeaderCassette(string $path, CassetteHeader $header, array $events = []): string
    {
        (new JsonlFormat())->write(new Cassette($header, $events), $path);
        return $path;
    }

    private function baseHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-07T10:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
    }

    /** @param callable(CassetteHeader): CassetteHeader $mutate */
    private function assertHeaderFieldDiffers(callable $mutate, string $needle): void
    {
        $a = $this->writeHeaderCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $this->baseHeader());
        $b = $this->writeHeaderCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), $mutate($this->baseHeader()));
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit, "expected diff for {$needle}, got: {$out}");
            $this->assertStringContainsString($needle, $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffTimestampModeMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, timestampMode: 'relative',
            ),
            'header.timestampMode:',
        );
    }

    public function testDiffCreatedAtMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: '2026-05-08T10:00:00Z', cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime,
            ),
            'header.created:',
        );
    }

    public function testDiffEnvMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, env: ['TERM' => 'xterm-256color'],
            ),
            'header.env:',
        );
    }

    public function testDiffThemeMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, theme: 'tokyo-night',
            ),
            'header.theme:',
        );
    }

    public function testDiffTypingSpeedMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, typingSpeed: 7.5,
            ),
            'header.typingSpeed:',
        );
    }

    public function testDiffPlaybackSpeedMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, playbackSpeed: 2.0,
            ),
            'header.playbackSpeed:',
        );
    }

    public function testDiffFontMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, fontSize: 16, fontFamily: 'JetBrains Mono',
            ),
            'header.fontSize:',
        );
    }

    public function testDiffFontFamilyMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, fontFamily: 'DejaVu Sans Mono',
            ),
            'header.fontFamily:',
        );
    }

    public function testDiffPixelGeometryMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, widthPx: 1234, heightPx: 567,
            ),
            'header.widthPx:',
        );
    }

    public function testDiffHeightMismatch(): void
    {
        $this->assertHeaderFieldDiffers(
            fn (CassetteHeader $h): CassetteHeader => new CassetteHeader(
                version: $h->version, createdAt: $h->createdAt, cols: $h->cols, rows: $h->rows,
                runtime: $h->runtime, heightPx: 567,
            ),
            'header.heightPx:',
        );
    }

    public function testDiffTimestampsDifferBeyondTolerance(): void
    {
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('event[0]: t differs', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffTimestampsWithinToleranceAreIdentical(): void
    {
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.001, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);

            $this->assertSame(0, $exit);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffIgnoresMigrationMetadataByDefault(): void
    {
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x', '_id' => 0, '_enc' => 'utf-8']),
        ]);
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $exit = (new DiffCommand())->run([$a, $b], $stdout, $stderr);

            $this->assertSame(0, $exit);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffStrictIncludesMigrationMetadata(): void
    {
        $a = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x']),
        ]);
        $b = $this->writeCassette(tempnam(sys_get_temp_dir(), 'cv-diff-'), [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'x', '_id' => 0, '_enc' => 'utf-8']),
        ]);
        try {
            $stdout = Stream::memory('w+');
            $stderr = Stream::memory('w+');
            $exit = (new DiffCommand())->run(['--strict', $a, $b], $stdout, $stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString('payload differs', $out);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testDiffUnknownOptionExitsTwo(): void
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $exit = (new DiffCommand())->run(['--bogus', '/no/such/a.cas', '/no/such/b.cas'], $stdout, $stderr);
        rewind($stderr);
        $err = (string) stream_get_contents($stderr);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('candy-vcr diff: unknown option --bogus', $err);
    }
}
