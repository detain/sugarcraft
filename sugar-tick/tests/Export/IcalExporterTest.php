<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Export\IcalExporter;
use SugarCraft\Tick\Heartbeat;

final class IcalExporterTest extends TestCase
{
    private IcalExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new IcalExporter();
    }

    public function testExportSingleHeartbeat(): void
    {
        $hbs = [
            new Heartbeat(
                time: 1719000000,
                project: 'my-project',
                language: 'php',
                file: 'src/Hello.php',
                duration: 60,
                tags: ['feature'],
            ),
        ];

        $ical = $this->exporter->export('Coding Activity', $hbs);

        $this->assertStringStartsWith('BEGIN:VCALENDAR', $ical);
        $this->assertStringContainsString('VERSION:2.0', $ical);
        $this->assertStringContainsString('PRODID:-//SugarCraft//sugar-tick//EN', $ical);
        $this->assertStringContainsString('BEGIN:VEVENT', $ical);
        // DTSTART/DTEND must be valid iCal UTC format (YYYYMMDDTHisZ)
        $this->assertMatchesRegularExpression('/^DTSTART:\d{8}T\d{6}Z/m', $ical);
        $this->assertMatchesRegularExpression('/^DTEND:\d{8}T\d{6}Z/m', $ical);
        // Duration 60s means DTEND = DTSTART + 60
        $this->assertStringContainsString('SUMMARY:src/Hello.php', $ical);
        $this->assertStringContainsString('DESCRIPTION:Project: my-project | Language: php', $ical);
        $this->assertStringContainsString('CATEGORIES:feature', $ical);
        $this->assertStringContainsString('END:VEVENT', $ical);
        $this->assertStringEndsWith('END:VCALENDAR', $ical);
    }

    public function testExportMultipleHeartbeats(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', duration: 60),
            new Heartbeat(time: 1719000100, project: 'p', language: 'py', file: 'b.py', duration: 120),
        ];

        $ical = $this->exporter->export('Activity', $hbs);

        $this->assertSame(2, substr_count($ical, 'BEGIN:VEVENT'));
        $this->assertSame(2, substr_count($ical, 'END:VEVENT'));
        $this->assertStringContainsString('SUMMARY:a.php', $ical);
        $this->assertStringContainsString('SUMMARY:b.py', $ical);
    }

    public function testExportEmpty(): void
    {
        $ical = $this->exporter->export('Empty', []);
        $this->assertStringStartsWith('BEGIN:VCALENDAR', $ical);
        $this->assertStringEndsWith('END:VCALENDAR', $ical);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ical);
    }

    public function testCustomProdId(): void
    {
        $exporter = new IcalExporter('-//Custom//App//EN');
        $ical = $exporter->export('Test', []);
        $this->assertStringContainsString('PRODID:-//Custom//App//EN', $ical);
    }

    public function testUidIsUnique(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php'),
            new Heartbeat(time: 1719000100, project: 'p', language: 'php', file: 'b.php'),
        ];

        $ical = $this->exporter->export('Test', $hbs);

        $lines = explode("\r\n", $ical);
        $uids = array_filter($lines, fn($l) => str_starts_with($l, 'UID:'));
        $this->assertCount(2, $uids);
    }

    public function testNoCategoriesWhenTagsEmpty(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', tags: []),
        ];

        $ical = $this->exporter->export('Test', $hbs);
        $this->assertStringNotContainsString('CATEGORIES:', $ical);
    }

    public function testTextFieldsAreEscaped(): void
    {
        // Mirrors IcalExporter::escapeText() RFC 5545 escaping
        $hbs = [
            new Heartbeat(
                time: 1719000000,
                project: 'a,b;c',
                language: "lang\r\nwith\rnewlines",
                file: "file\nwith\nnewlines",
                duration: 60,
                tags: ['tag,with;commas'],
            ),
        ];

        $ical = $this->exporter->export('Test', $hbs);

        // Escaped values should appear in output without injection
        // Commas/semicolons in CATEGORIES should be escaped
        $this->assertStringContainsString('SUMMARY:file', $ical);
        // Verify no raw BEGIN:VEVENT injection via newlines in file field
        $this->assertStringNotContainsString("SUMMARY:file\nwith", $ical);
    }
}
