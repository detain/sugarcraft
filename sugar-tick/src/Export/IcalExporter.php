<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Export;

use SugarCraft\Tick\Heartbeat;

/**
 * Exports heartbeats as iCalendar (RFC 5545) format.
 * Each heartbeat becomes a VEVENT with the file path as the summary.
 */
final readonly class IcalExporter
{
    public function __construct(
        private string $prodId = '-//SugarCraft//sugar-tick//EN',
    ) {}

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
            $lines[] = 'SUMMARY:' . $hb->file;
            $lines[] = 'DESCRIPTION:Project: ' . $hb->project . ' | Language: ' . $hb->language;
            if ($hb->tags !== []) {
                $lines[] = 'CATEGORIES:' . implode(',', $hb->tags);
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines);
    }
}
