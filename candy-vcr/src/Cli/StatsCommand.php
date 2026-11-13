<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Support\Scalars;

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
 * - 2: usage error (missing positional arg or unknown option)
 */
final class StatsCommand implements DocumentedCommand
{
    public function summary(): string
    {
        return 'Show cassette statistics';
    }

    public function usage(): string
    {
        return 'usage: candy-vcr stats <cassette>' . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        // Sibling verbs (record/replay/inspect/migrate) refuse unknown
        // options loudly; stats used to swallow every '--…' argument, so a
        // mistyped flag silently changed nothing.
        $paths = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr stats: unknown option {$arg}\n");
                return 2;
            }
            $paths[] = $arg;
        }
        if ($paths === []) {
            fwrite($stderr, $this->usage());
            return 2;
        }
        if (count($paths) > 1) {
            fwrite($stderr, "candy-vcr stats: unexpected argument {$paths[1]}\n");
            return 2;
        }
        $path = $paths[0];

        $loader = new CassetteLoader();
        try {
            $cassette = $loader->load($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr stats: {$e->getMessage()}\n");
            return 1;
        }
        CassetteLoader::writeTapeWarnings($loader, $stderr, 'stats');

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
            "cassette v%d  %dx%d\n  runtime=%s  created=%s\n",
            $cassette->header->version,
            $cassette->header->cols,
            $cassette->header->rows,
            self::displaySafe($cassette->header->runtime),
            self::displaySafe($cassette->header->createdAt),
        ));
        fwrite($stdout, str_repeat('-', 72) . "\n");
    }

    /**
     * Belt for finding 004 (the CassetteHeader door is the braces): every
     * non-printable byte becomes '.' so a header string can never smuggle
     * escape sequences to the host through `stats`, whatever produced the
     * object. Same shape as InspectCommand::displaySafe().
     */
    private static function displaySafe(string $text): string
    {
        return \preg_replace('/[^\x20-\x7e]/', '.', $text) ?? '';
    }

    /**
     * @param resource $stdout
     */
    private function renderEventTallies($stdout, Cassette $cassette): void
    {
        // Every EventKind tallies; zero-count kinds stay unprinted, but a
        // snapshot/hide/show event must never be silently invisible.
        $tallies = ['input' => 0, 'output' => 0, 'resize' => 0, 'snapshot' => 0, 'hide' => 0, 'show' => 0, 'quit' => 0];
        foreach ($cassette->events as $event) {
            $key = $event->kind->value;
            if (isset($tallies[$key])) {
                $tallies[$key]++;
            }
        }

        $parts = [];
        foreach ($tallies as $kind => $count) {
            if ($count > 0) {
                $parts[] = sprintf("%s: %d", $kind, $count);
            }
        }
        $line = "Events: {$cassette->eventCount()}" . ($parts === [] ? '' : '  ' . implode('  ', $parts));
        // Long tallies (all seven kinds live) would run past 80 columns.
        fwrite($stdout, wordwrap($line, 78, "\n  ", false) . "\n");
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
            $msg = $event->payload['msg'] ?? null;
            if (is_array($msg) && isset($msg['@type'])) {
                $type = Scalars::string($msg['@type'], 'stats msg @type');
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
