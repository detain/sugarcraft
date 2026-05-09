<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * `candy-vcr diff <a.cas> <b.cas>`
 *
 * Compare two cassettes structurally — header fields, event count,
 * and per-event kind/payload. Exits 0 if identical, 1 if they differ.
 */
final class DiffCommand implements Command
{
    public function summary(): string
    {
        return 'Compare two cassettes event-by-event';
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $paths = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')));
        if (count($paths) !== 2) {
            fwrite($stderr, "usage: candy-vcr diff <a.cas> <b.cas>\n");
            return 2;
        }
        try {
            $a = (new JsonlFormat())->read($paths[0]);
            $b = (new JsonlFormat())->read($paths[1]);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr diff: {$e->getMessage()}\n");
            return 1;
        }

        $diffs = $this->collectDiffs($a, $b);
        if ($diffs === []) {
            fwrite($stdout, "cassettes are identical (modulo `t` rounding)\n");
            return 0;
        }
        foreach ($diffs as $line) {
            fwrite($stdout, $line . "\n");
        }
        fwrite($stdout, sprintf("\n%d difference(s)\n", count($diffs)));
        return 1;
    }

    /** @return list<string> */
    private function collectDiffs(Cassette $a, Cassette $b): array
    {
        $diffs = [];

        if ($a->header->version !== $b->header->version) {
            $diffs[] = sprintf('header.v: %d != %d', $a->header->version, $b->header->version);
        }
        if ($a->header->cols !== $b->header->cols || $a->header->rows !== $b->header->rows) {
            $diffs[] = sprintf(
                'header dimensions: %dx%d != %dx%d',
                $a->header->cols, $a->header->rows, $b->header->cols, $b->header->rows,
            );
        }
        if ($a->header->runtime !== $b->header->runtime) {
            $diffs[] = sprintf('header.runtime: %s != %s', $a->header->runtime, $b->header->runtime);
        }

        $aCount = $a->eventCount();
        $bCount = $b->eventCount();
        if ($aCount !== $bCount) {
            $diffs[] = sprintf('event count: %d != %d', $aCount, $bCount);
        }

        $max = max($aCount, $bCount);
        for ($i = 0; $i < $max; $i++) {
            $aEvent = $a->events[$i] ?? null;
            $bEvent = $b->events[$i] ?? null;

            if ($aEvent === null) {
                $diffs[] = sprintf('event[%d]: missing in A, present in B (%s)', $i, $bEvent->kind->value);
                continue;
            }
            if ($bEvent === null) {
                $diffs[] = sprintf('event[%d]: present in A (%s), missing in B', $i, $aEvent->kind->value);
                continue;
            }
            if ($aEvent->kind !== $bEvent->kind) {
                $diffs[] = sprintf(
                    'event[%d]: kind %s != %s',
                    $i, $aEvent->kind->value, $bEvent->kind->value,
                );
            }
            if ($aEvent->payload !== $bEvent->payload) {
                $diffs[] = sprintf('event[%d] payload differs (%s)', $i, $aEvent->kind->value);
            }
        }

        return $diffs;
    }
}
