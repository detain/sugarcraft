<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Export\CsvExporter;
use SugarCraft\Tick\Heartbeat;

final class CsvExporterTest extends TestCase
{
    private CsvExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new CsvExporter();
    }

    public function testHeaders(): void
    {
        $this->assertSame(['time', 'project', 'language', 'file', 'duration', 'tags'], $this->exporter->headers());
    }

    public function testRowsEmptyList(): void
    {
        $this->assertSame([], $this->exporter->rows([]));
    }

    public function testRowsSingleHeartbeat(): void
    {
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $rows = $this->exporter->rows([$hb]);
        $this->assertCount(1, $rows);
        $this->assertSame([1700000000, 'demo', 'php', 'a.php', 60, ''], $rows[0]);
    }

    public function testRowsWithTags(): void
    {
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60, tags: ['refactoring', 'feature']);
        $rows = $this->exporter->rows([$hb]);
        $this->assertSame([1700000000, 'demo', 'php', 'a.php', 60, 'refactoring,feature'], $rows[0]);
    }

    public function testRowsMultipleHeartbeats(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60, tags: ['tag1']);
        $hb2 = new Heartbeat(time: 1700000060, project: 'demo', language: 'php', file: 'b.php', duration: 120, tags: []);
        $rows = $this->exporter->rows([$hb1, $hb2]);
        $this->assertCount(2, $rows);
        $this->assertSame([1700000000, 'demo', 'php', 'a.php', 60, 'tag1'], $rows[0]);
        $this->assertSame([1700000060, 'demo', 'php', 'b.php', 120, ''], $rows[1]);
    }

    public function testFormat(): void
    {
        $this->assertSame('csv', $this->exporter->format());
    }

    public function testContentType(): void
    {
        $this->assertSame('text/csv', $this->exporter->contentType());
    }
}
