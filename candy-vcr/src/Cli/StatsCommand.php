<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * `candy-vcr stats <cassette>`
 *
 * Print statistical summary of a cassette: event tallies by kind,
 * total duration, output byte counts, and a breakdown of input
 * message types. Useful for CI diagnostics and cassette profiling.
 *
 * Exit codes:
 * - 0: success
 * - 1: file read error
 * - 2: usage error (missing positional arg)
 */
final class StatsCommand implements Command
{
    public function summary(): string
    {
        return 'Show cassette statistics';
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')))[0] ?? null;

        if ($path === null) {
            fwrite($stderr, "usage: candy-vcr stats <cassette>\n");
            return 2;
        }

        try {
            $cassette = (new JsonlFormat())->read($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr stats: {$e->getMessage()}\n");
            return 1;
        }

        $this->renderStats($stdout, $cassette);
        return 0;
    }

    /**
     * Render all statistics for the given cassette.
     *
     * @param resource $stdout
     */
    private function renderStats($stdout, Cassette $cassette): void
    {
        $this->renderHeader($stdout, $cassette);
        $this->renderEventTallies($stdout, $cassette);
        $this->renderDuration($stdout, $cassette);
        $this->renderInputBreakdown($stdout, $cassette);
        $this->renderOutputBytes($stdout, $cassette);
    }

    /**
     * @param resource $stdout
     */
    private function renderHeader($stdout, Cassette $cassette): void
    {
        fwrite($stdout, sprintf(
            "cassette v%d  %dx%d  runtime=%s  created=%s\n",
            $cassette->header->version,
            $cassette->header->cols,
            $cassette->header->rows,
            $cassette->header->runtime,
            $cassette->header->createdAt,
        ));
        fwrite($stdout, str_repeat('-', 72) . "\n");
    }

    /**
     * @param resource $stdout
     */
    private function renderEventTallies($stdout, Cassette $cassette): void
    {
        $tallies = ['input' => 0, 'output' => 0, 'resize' => 0, 'quit' => 0];
        foreach ($cassette->events as $event) {
            $key = $event->kind->value;
            if (isset($tallies[$key])) {
                $tallies[$key]++;
            }
        }

        fwrite($stdout, "Events: {$cassette->eventCount()}");
        foreach ($tallies as $kind => $count) {
            if ($count > 0) {
                fwrite($stdout, sprintf("  %s: %d", $kind, $count));
            }
        }
        fwrite($stdout, "\n");
    }

    /**
     * @param resource $stdout
     */
    private function renderDuration($stdout, Cassette $cassette): void
    {
        $duration = $cassette->duration();
        fwrite($stdout, sprintf("Duration: %.3fs\n", $duration));
    }

    /**
     * Break down input events by their `@type` label.
     *
     * @param resource $stdout
     */
    private function renderInputBreakdown($stdout, Cassette $cassette): void
    {
        /** @var array<string, int> $msgCounts */
        $msgCounts = [];
        $rawByteCount = 0;

        foreach ($cassette->events as $event) {
            if ($event->kind !== EventKind::Input) {
                continue;
            }
            if (isset($event->payload['msg']['@type'])) {
                $type = (string) $event->payload['msg']['@type'];
                $msgCounts[$type] = ($msgCounts[$type] ?? 0) + 1;
            } elseif (isset($event->payload['b'])) {
                $rawByteCount++;
            }
        }

        if ($msgCounts === [] && $rawByteCount === 0) {
            return;
        }

        $parts = [];
        foreach ($msgCounts as $type => $count) {
            $parts[] = "{$type}({$count})";
        }
        if ($rawByteCount > 0) {
            $parts[] = "raw bytes({$rawByteCount})";
        }
        fwrite($stdout, "Input msgs: " . implode(', ', $parts) . "\n");
    }

    /**
     * @param resource $stdout
     */
    private function renderOutputBytes($stdout, Cassette $cassette): void
    {
        $totalBytes = 0;
        $outputCount = 0;

        foreach ($cassette->events as $event) {
            if ($event->kind !== EventKind::Output) {
                continue;
            }
            if (isset($event->payload['b']) && is_string($event->payload['b'])) {
                $totalBytes += strlen($event->payload['b']);
                $outputCount++;
            }
        }

        fwrite($stdout, sprintf("Output bytes: %d\n", $totalBytes));

        if ($outputCount > 0) {
            $avg = $totalBytes / $outputCount;
            fwrite($stdout, sprintf("Avg output/event: %.1f bytes\n", $avg));
        }
    }
}
