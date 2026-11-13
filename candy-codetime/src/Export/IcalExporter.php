<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Export;

use SugarCraft\CodeTime\Heartbeat;

/**
 * Exports heartbeats as iCalendar (RFC 5545) format.
 * Each heartbeat becomes a VEVENT with the file path as the summary.
 */
final readonly class IcalExporter implements ExporterInterface
{
    public function __construct(
        private string $prodId = '-//SugarCraft//candy-codetime//EN',
    ) {}

    /**
     * Escape a TEXT field value per RFC 5545.
     */
    private static function escapeText(string $v): string
    {
        $v = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $v);
        $v = preg_replace('/\r\n|\r|\n/', '\\n', $v) ?? $v;
        return $v;
    }

    /**
     * Enforce RFC 5545 §3.1's 75-octet line ceiling on one content line.
     *
     * Longer lines are split with "\r\n " (CRLF plus one space); the cut
     * never lands inside a multi-byte UTF-8 sequence, so a client that
     * unfolds by stripping "\r\n" and the space gets back exactly what
     * was put in. Deep absolute paths from editor plugins are the
     * everyday producer of >75-octet SUMMARY lines — an unfolded file
     * is what strict importers reject or silently truncate.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $limit = 75;
        $offset = 0;
        $length = strlen($line);
        while ($offset < $length) {
            $cut = min($offset + $limit, $length);
            if ($cut < $length) {
                // Step back off a continuation byte (10xxxxxx) to the
                // start of the character the cut would have split.
                while ($cut > $offset && (ord($line[$cut]) & 0xC0) === 0x80) {
                    --$cut;
                }
            }
            $chunks[] = substr($line, $offset, $cut - $offset);
            $offset = $cut;
            $limit = 74; // Continuation lines count the fold space toward 75.
        }

        return implode("\r\n ", $chunks);
    }

    /**
     * Export heartbeats as iCal format.
     * Each heartbeat becomes a VEVENT with the file path as the summary.
     *
     * @param list<Heartbeat> $heartbeats
     */
    public function export(string $name, array $heartbeats): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            // De-facto (not RFC) calendar-name property honoured by the
            // major importers; it is what the $name argument is for.
            'X-WR-CALNAME:' . self::escapeText($name),
        ];

        foreach ($heartbeats as $index => $hb) {
            $start = gmdate('Ymd\THis\Z', $hb->time);
            $end = gmdate('Ymd\THis\Z', $hb->time + $hb->duration);
            $lines[] = 'BEGIN:VEVENT';
            // A beat's identity is its content plus its position in the
            // exported window: two same-file same-second beats (duration
            // backdating, plugin retry) must not collapse into one
            // calendar event. The store keeps duplicates on purpose and
            // their sums ride the CSV/JSON exports and the totals, so
            // the calendar leg agrees: UID mixes duration and the row
            // sequence. Stance: re-exporting the same window reproduces
            // the same UIDs (idempotent), while a shifted window may
            // re-record an overlapping beat under a fresh UID —
            // at-least-once, chosen deliberately over silent event loss.
            $uid = md5($hb->file . '|' . $hb->time . '|' . $hb->duration . '|' . $index);
            $lines[] = 'UID:' . $uid . '@candy-codetime';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $start;
            $lines[] = 'DTEND:' . $end;
            $lines[] = 'SUMMARY:' . self::escapeText($hb->file);
            $lines[] = 'DESCRIPTION:Project: ' . self::escapeText($hb->project) . ' | Language: ' . self::escapeText($hb->language);
            if ($hb->tags !== []) {
                $lines[] = 'CATEGORIES:' . implode(',', array_map(self::escapeText(...), $hb->tags));
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 wants every line, the last one included, CRLF-terminated.
        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    public function format(): string
    {
        return 'ics';
    }

    public function contentType(): string
    {
        return 'text/calendar';
    }
}
