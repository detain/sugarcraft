<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests\Export;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Export\IcalExporter;
use SugarCraft\CodeTime\Heartbeat;

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
        $this->assertStringContainsString('PRODID:-//SugarCraft//candy-codetime//EN', $ical);
        $this->assertStringContainsString('BEGIN:VEVENT', $ical);
        // DTSTART/DTEND must be valid iCal UTC format (YYYYMMDDTHisZ)
        $this->assertMatchesRegularExpression('/^DTSTART:\d{8}T\d{6}Z/m', $ical);
        $this->assertMatchesRegularExpression('/^DTEND:\d{8}T\d{6}Z/m', $ical);
        // Duration 60s means DTEND = DTSTART + 60
        $this->assertStringContainsString('SUMMARY:src/Hello.php', $ical);
        $this->assertStringContainsString('DESCRIPTION:Project: my-project | Language: php', $ical);
        $this->assertStringContainsString('CATEGORIES:feature', $ical);
        $this->assertStringContainsString('END:VEVENT', $ical);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ical); // CT-S18: every line, last included, CRLF-terminated
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
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ical); // CT-S18: every line, last included, CRLF-terminated
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

    /**
     * CT-S18 (finding 044): the pins that catch invalid iCal, named after
     * what they actually verify.
     */
    public function testDatedLinesRoundTripToTheBeatDuration(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', duration: 120),
        ];

        $ical = $this->exporter->export('Test', $hbs);

        $this->assertSame(1, preg_match('/^DTSTART:(\d{8}T\d{6}Z)\r$/m', $ical, $start));
        $this->assertSame(1, preg_match('/^DTEND:(\d{8}T\d{6}Z)\r$/m', $ical, $end));
        $from = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $start[1], new \DateTimeZone('UTC'));
        $to = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $end[1], new \DateTimeZone('UTC'));
        $this->assertNotFalse($from);
        $this->assertNotFalse($to);
        $this->assertSame(120, $to->getTimestamp() - $from->getTimestamp());
    }

    public function testTwoSameFileSameSecondBeatsKeepDistinctUids(): void
    {
        // Duration backdating and plugin retries mint these: the store
        // keeps both rows and their sums ride every other surface, so
        // the calendar leg must not silently dedupe them on import.
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', duration: 60),
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', duration: 90),
        ];

        $ical = $this->exporter->export('Test', $hbs);

        $uids = [];
        foreach (explode("\r\n", $ical) as $line) {
            if (str_starts_with($line, 'UID:')) {
                $uids[] = $line;
            }
        }
        $this->assertCount(2, $uids);
        $this->assertCount(2, array_unique($uids));
    }

    public function testReExportingTheSameWindowIsByteIdentical(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: 'a.php', duration: 60),
            new Heartbeat(time: 1719003600, project: 'p', language: 'php', file: 'b.php', duration: 60),
        ];

        $first = $this->exporter->export('T', $hbs);
        $second = $this->exporter->export('T', $hbs);
        $this->assertSame(
            $this->strip($first),
            $this->strip($second),
            'UIDs and summaries must reproduce across re-exports of the same window (DTSTAMP excluded)',
        );
    }

    public function testEveryPhysicalLineStaysUnderTheOctetCeiling(): void
    {
        $deep = '/home/dev/projects/' . str_repeat('nested-directory/', 12) . 'Über-touch-π.php';
        $hbs = [
            new Heartbeat(time: 1719000000, project: 'p', language: 'php', file: $deep, duration: 60),
        ];

        $ical = $this->exporter->export($deep, $hbs);

        foreach (explode("\r\n", $ical) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'RFC 5545 §3.1 caps content lines at 75 octets: ' . $line);
        }
        // Unfolding must restore the exact value, byte for byte, with no
        // split multi-byte sequence anywhere.
        $unfolded = str_replace("\r\n ", '', $ical);
        $this->assertStringContainsString('SUMMARY:' . $this->escapeExpected($deep), $unfolded);
    }

    public function testPositiveEscapeFormsArePaintedVerbatim(): void
    {
        $hbs = [
            new Heartbeat(
                time: 1719000000,
                project: 'p',
                language: 'php',
                file: 'one\\two;three,four' . "\n" . 'tail',
                duration: 60,
                tags: ['tag,with;commas'],
            ),
        ];

        $ical = $this->exporter->export('Test', $hbs);
        $unfolded = str_replace("\r\n ", '', $ical);

        $this->assertStringContainsString(
            'SUMMARY:one\\\\two\\;three\\,four\\ntail',
            $unfolded,
        );
        $this->assertStringContainsString('CATEGORIES:tag\\,with\\;commas', $unfolded);
        $this->assertStringContainsString('X-WR-CALNAME:Test', $ical);
    }

    public function testNoBareLineFeedEverLeaksIntoTheStream(): void
    {
        $hbs = [
            new Heartbeat(time: 1719000000, project: "lf\nproject", language: 'php', file: "crlf\r\nfile", duration: 60),
        ];

        $ical = $this->exporter->export('Test', $hbs);

        $this->assertSame(0, \preg_match('/(?<!\r)\n/', $ical), 'every LF must ride a CRLF');
    }

    /** escapeText mirror for the assertion above (test-side so the pin stays honest). */
    private function escapeExpected(string $v): string
    {
        $v = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $v);
        return \preg_replace('/\r\n|\r|\n/', '\\\\n', $v) ?? $v;
    }

    /** Drop the wall-clock DTSTAMP so byte comparison of two runs is meaningful. */
    private function strip(string $ical): string
    {
        return \preg_replace('/^DTSTAMP:.*$/m', '', $ical) ?? $ical;
    }
}
