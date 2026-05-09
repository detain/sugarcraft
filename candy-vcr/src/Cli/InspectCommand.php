<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * `candy-vcr inspect <cassette> [--since=<seconds>] [--until=<seconds>]`
 *
 * Pretty-prints the events in a JSONL cassette: timestamp, kind, and
 * a one-line summary of the payload.
 */
final class InspectCommand implements Command
{
    public function summary(): string
    {
        return 'List the events in a cassette';
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = null;
        $since = null;
        $until = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--since=')) {
                $since = (float) substr($arg, 8);
            } elseif (str_starts_with($arg, '--until=')) {
                $until = (float) substr($arg, 8);
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr inspect: unknown option {$arg}\n");
                return 2;
            } else {
                $path = $arg;
            }
        }

        if ($path === null) {
            fwrite($stderr, "usage: candy-vcr inspect <cassette> [--since=<seconds>] [--until=<seconds>]\n");
            return 2;
        }

        try {
            $cassette = (new JsonlFormat())->read($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr inspect: {$e->getMessage()}\n");
            return 1;
        }

        $this->renderHeader($stdout, $cassette);
        $shown = 0;
        foreach ($cassette->events as $event) {
            if ($since !== null && $event->t < $since) continue;
            if ($until !== null && $event->t > $until) continue;
            fwrite($stdout, $this->formatEvent($event) . "\n");
            $shown++;
        }
        fwrite($stdout, sprintf("\n%d / %d event(s) shown\n", $shown, $cassette->eventCount()));
        return 0;
    }

    /**
     * @param resource $stdout
     */
    private function renderHeader($stdout, Cassette $cassette): void
    {
        fwrite($stdout, sprintf(
            "cassette v%d  %dx%d  runtime=%s  created=%s  duration=%.3fs  events=%d\n",
            $cassette->header->version,
            $cassette->header->cols,
            $cassette->header->rows,
            $cassette->header->runtime,
            $cassette->header->createdAt,
            $cassette->duration(),
            $cassette->eventCount(),
        ));
        fwrite($stdout, str_repeat('-', 72) . "\n");
    }

    private function formatEvent(\SugarCraft\Vcr\Event $event): string
    {
        $base = sprintf('  t=%.3fs  %-7s', $event->t, $event->kind->value);
        return $base . '  ' . match ($event->kind) {
            EventKind::Resize => sprintf(
                '%dx%d',
                (int) ($event->payload['cols'] ?? 0),
                (int) ($event->payload['rows'] ?? 0),
            ),
            EventKind::Output => $this->summarizeBytes((string) ($event->payload['b'] ?? '')),
            EventKind::Input => isset($event->payload['msg'])
                ? '@' . ($event->payload['msg']['@type'] ?? '?')
                : $this->summarizeBytes((string) ($event->payload['b'] ?? '')),
            EventKind::Quit => '',
        };
    }

    private function summarizeBytes(string $bytes): string
    {
        $len = strlen($bytes);
        $printable = preg_replace('/[^\x20-\x7e]/', '.', $bytes) ?? '';
        $shown = strlen($printable) > 40 ? substr($printable, 0, 40) . '…' : $printable;
        return sprintf('%d bytes  %s', $len, $shown);
    }
}
