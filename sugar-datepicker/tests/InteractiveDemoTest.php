<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the honesty of examples/interactive.php: the demo must complete
 * its full script even when stdin is closed at once (the piped-CI shape),
 * and every transcript line must be either a real wire event or explicitly
 * `[host]`-labelled — never a host invention formatted like an event.
 *
 * Mirrors sugar-datepicker-013/sugar-datepicker-053 (dp-12).
 */
final class InteractiveDemoTest extends TestCase
{
    /**
     * Kinds the EventStore actually emits (PickerEvent wire values).
     * A transcript line naming anything else is a fabricated event.
     */
    private const WIRE_KINDS = [
        'cursor_moved',
        'range_started',
        'range_restarted',
        'range_cleared',
        'date_selected',
        'date_cleared',
        'month_changed',
        'selection_refused',
    ];

    public function testDemoCompletesItsScriptAgainstClosedStdin(): void
    {
        $output = self::runDemo();

        // A truncated run is the exact stdin-EOF regression the socket-pair
        // input prevents: the old demo printed 2 of 8 lines this way.
        self::assertStringNotContainsString('no-event', $output, 'filler lines fabricated a non-store "event"');
        self::assertStringNotContainsString('-> mode', $output, 'host mode flip must not look like a wire event');
        self::assertStringContainsString('[host] range-mode toggled', $output);
        self::assertStringContainsString('mode range', $output, 'truncated run silently ends in single mode');
        self::assertStringContainsString('"rangeStart":"2026-05-09","rangeEnd":"2026-05-10"', $output);

        $lines = \explode("\n", $output);
        $headingIdx = null;
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, 'Demo transcript (')) {
                $headingIdx = $i;
                break;
            }
        }
        self::assertNotNull($headingIdx, 'transcript heading missing');
        self::assertMatchesRegularExpression('/8 lines from 8 scripted messages/', $lines[$headingIdx]);

        $transcript = [];
        foreach (\array_slice($lines, $headingIdx + 1) as $line) {
            if (\trim($line) !== '') {
                $transcript[] = \ltrim($line);
            }
        }
        self::assertCount(8, $transcript, 'full script must yield its whole transcript: ' . \implode(' | ', $transcript));
    }

    public function testEveryTranscriptLineIsARealWireEventOrLabelledHostLine(): void
    {
        $output = self::runDemo();
        $headingIdx = null;
        $lines = \explode("\n", $output);
        foreach ($lines as $i => $line) {
            if (\str_starts_with($line, 'Demo transcript (')) {
                $headingIdx = $i;
            }
        }
        self::assertNotNull($headingIdx);

        $sawEvent = false;
        foreach (\array_slice($lines, $headingIdx + 1) as $line) {
            $line = \ltrim($line);
            if ($line === '') {
                continue;
            }
            if (\str_starts_with($line, '[host] ')) {
                continue; // labelled host-local line — cannot be mistaken for an event
            }
            self::assertMatchesRegularExpression(
                '/^\S+\s+->\s+(' . \implode('|', self::WIRE_KINDS) . ')\s+\{.*\}$/',
                $line,
                'unlabelled transcript line is not a real wire event',
            );
            $sawEvent = true;
        }
        self::assertTrue($sawEvent, 'transcript carried no store events at all');
    }

    private static function runDemo(): string
    {
        $script = \dirname(__DIR__) . '/examples/interactive.php';
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script);

        $process = \proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($process);

        self::assertSame(0, $exit, 'demo must self-exit cleanly with stdin closed; stderr: ' . $stderr);

        return $stdout;
    }
}
