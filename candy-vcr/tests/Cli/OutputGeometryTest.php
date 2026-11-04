<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Cli\InspectCommand;
use SugarCraft\Vcr\Cli\ReplayCommand;
use SugarCraft\Vcr\Cli\StatsCommand;
use SugarCraft\Core\Util\Width;
use SugarCraft\Vcr\Diff\DiffWriter;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * mi16: emitted CLI geometry. Usage homes, the stats tally row, and the
 * diff box must all fit an 80-column terminal, and the two subcommand
 * groups in the top-level help must share one description column.
 */
final class OutputGeometryTest extends TestCase
{
    /** @return array{0: resource, 1: resource} */
    private function memoryPair(): array
    {
        return [fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
    }

    /** @param resource $stream */
    private function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function stripSgr(string $text): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $text);
    }

    public function testInspectUsageFitsEightyColumns(): void
    {
        $lines = explode("\n", (new InspectCommand())->usage());
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(80, strlen($line), 'inspect usage line too long: ' . $line);
        }
        self::assertSame(1, substr_count(implode("\n", $lines), 'usage: candy-vcr inspect'));
        self::assertStringContainsString('--since=<seconds>', $lines[1]);
    }

    public function testReplayUsageFitsEightyColumnsAndNamesBothTrimSpellings(): void
    {
        $usage = (new ReplayCommand())->usage();
        foreach (explode("\n", $usage) as $line) {
            self::assertLessThanOrEqual(80, strlen($line), 'replay usage line too long: ' . $line);
        }
        self::assertSame(1, substr_count($usage, 'usage: candy-vcr replay'));
        self::assertStringContainsString('--idle-trim=N|--idle-trim N', $usage);
    }

    public function testDiffBoxIsSymmetricEvenWhenContentIsTiny(): void
    {
        [$out, $err] = $this->memoryPair();
        (new DiffWriter())->writeAnsiDiff('a', 'b', $out);
        $text = $this->stripSgr($this->drain($out));

        $rows = array_values(array_filter(explode("\n", $text), static fn (string $l): bool => trim($l, "\r") !== ''));
        $widths = array_unique(array_map(static fn (string $l): int => Width::of(rtrim($l, "\r")), $rows));
        self::assertCount(1, $widths, 'every box row must share one width, got: ' . $text);

        // Pre-fix this crash-shape existed too: str_repeat with a negative
        // count when the content was narrower than the header labels.
        self::assertStringContainsString('EXPECTED', $text);
        self::assertStringContainsString('ACTUAL', $text);
    }

    public function testDiffBoxHeadersAlignWithContentRows(): void
    {
        [$out, $err] = $this->memoryPair();
        (new DiffWriter())->writeAnsiDiff('one line here', 'other line here', $out);
        $rows = array_map(
            static fn (string $l): int => Width::of(rtrim($l, "\r")),
            array_values(array_filter(
                explode("\n", $this->stripSgr($this->drain($out))),
                static fn (string $l): bool => trim($l, "\r") !== '',
            )),
        );
        self::assertSame([$rows[0]], array_values(array_unique($rows)));
    }

    public function testStatsTallyRowWrapsWithinEightyColumns(): void
    {
        $dir = \sys_get_temp_dir() . '/vcr-geom-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            $events = [];
            foreach (EventKind::cases() as $i => $kind) {
                $events[] = new Event(
                    t: (float) $i,
                    kind: $kind,
                    payload: $kind === EventKind::Output ? ['b' => 'x'] : [],
                );
            }
            $cassette = new Cassette(
                new CassetteHeader(
                    version: CassetteHeader::CURRENT_VERSION,
                    createdAt: '2026-05-08T12:00:00Z',
                    cols: 80,
                    rows: 24,
                    runtime: 'sugarcraft/candy-vcr@dev',
                ),
                $events,
            );
            $path = $dir . '/all-seven.cas';
            (new JsonlFormat())->write($cassette, $path);

            [$out, $err] = $this->memoryPair();
            self::assertSame(0, (new StatsCommand())->run([$path], $out, $err));
            $text = $this->drain($out);

            foreach (explode("\n", $text) as $line) {
                self::assertLessThanOrEqual(80, strlen($line), 'stats line too wide: ' . $line);
            }
            self::assertStringContainsString('Events: 7', $text);
            foreach (EventKind::cases() as $kind) {
                self::assertStringContainsString($kind->value . ': 1', $text);
            }
        } finally {
            @unlink($dir . '/all-seven.cas');
            @rmdir($dir);
        }
    }

    public function testTopLevelHelpPadsEveryVerbToTheSameColumn(): void
    {
        [$out, $err] = $this->memoryPair();
        (new Application())->run(['candy-vcr'], $out, $err);

        $columns = [];
        $listed = 0;
        foreach (explode("\n", $this->drain($out)) as $line) {
            if (preg_match('/^  ([a-z-]+)( +)(\S.*)$/', $line, $m) === 1) {
                $listed++;
                $columns[strpos($line, $m[3])] = true;
            }
        }
        self::assertGreaterThanOrEqual(3, $listed, 'expected hand-rolled AND Symfony verbs listed');
        self::assertCount(1, $columns, 'descriptions must start at one shared column');
    }
}
