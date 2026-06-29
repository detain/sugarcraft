<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as iCalendar (RFC 5545) format.
 * Each heartbeat becomes a VEVENT with the file path as the summary.
 */
final readonly class IcalExporter implements ExporterInterface
{
    public function __construct(
        private string $prodId = '-//SugarCraft//sugar-tick//EN',
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
        ];

        foreach ($heartbeats as $hb) {
            $start = gmdate('Ymd\THis\Z', $hb->time);
            $end = gmdate('Ymd\THis\Z', $hb->time + $hb->duration);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . md5($hb->file . $hb->time) . '@sugar-tick';
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
        return implode("\r\n", $lines);
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
