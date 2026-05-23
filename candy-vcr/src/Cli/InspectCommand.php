<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;
use SugarCraft\Vt\Theme;

/**
 * `candy-vcr inspect <cassette|.tape> [--since=<seconds>] [--until=<seconds>] [--frames] [--fps=<n>]`
 *
 * Pretty-prints the events in a cassette: timestamp, kind, and a one-line
 * summary of the payload. Accepts either a JSONL/Relative/Yaml/asciinema
 * cassette or a `.tape` source file (auto-detected via
 * {@see CassetteLoader}).
 *
 * `--frames` switches to the frame timeline view: walks the cassette through
 * a Renderer + Terminal exactly as `render-tape` does, then prints one line
 * per Snapshot with `time<TAB>cursor_row,cursor_col<TAB>grid_sha1`. The
 * grid hash digests `row|col|char|fg|bg|attrs` tuples in deterministic
 * row-major order so two grids producing the same visible output share a
 * hash regardless of cell-construction order.
 */
final class InspectCommand implements Command
{
    public function summary(): string
    {
        return 'List the events (or frames with --frames) in a cassette';
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = null;
        $since = null;
        $until = null;
        $frames = false;
        $fps = 30.0;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--since=')) {
                $since = (float) substr($arg, 8);
            } elseif (str_starts_with($arg, '--until=')) {
                $until = (float) substr($arg, 8);
            } elseif (str_starts_with($arg, '--fps=')) {
                $fps = (float) substr($arg, 6);
                if ($fps <= 0.0) {
                    fwrite($stderr, "candy-vcr inspect: --fps must be > 0\n");
                    return 2;
                }
            } elseif ($arg === '--frames') {
                $frames = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr inspect: unknown option {$arg}\n");
                return 2;
            } else {
                $path = $arg;
            }
        }

        if ($path === null) {
            fwrite($stderr, "usage: candy-vcr inspect <cassette|.tape> [--since=<seconds>] [--until=<seconds>] [--frames] [--fps=<n>]\n");
            return 2;
        }

        try {
            $cassette = (new CassetteLoader())->load($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr inspect: {$e->getMessage()}\n");
            return 1;
        }

        $this->renderHeader($stdout, $cassette);

        if ($frames) {
            return $this->renderFrames($stdout, $cassette, $fps);
        }

        $shown = 0;
        foreach ($cassette->events as $event) {
            if ($since !== null && $event->t < $since) {
                continue;
            }
            if ($until !== null && $event->t > $until) {
                continue;
            }
            fwrite($stdout, $this->formatEvent($event) . "\n");
            $shown++;
        }
        fwrite($stdout, sprintf("\n%d / %d event(s) shown\n", $shown, $cassette->eventCount()));
        return 0;
    }

    /**
     * Walk the cassette through a Renderer + Terminal at `$fps` and print
     * one line per snapshot. Closes with a `frames: <total> unique: <dedup>`
     * footer so the dedup ratio is visible at a glance.
     *
     * @param resource $stdout
     */
    private function renderFrames($stdout, Cassette $cassette, float $fps): int
    {
        $terminal = Terminal::new(
            $cassette->header->cols,
            $cassette->header->rows,
            $this->resolveTheme($cassette->header->theme),
        );
        $player = new Player($cassette);
        $stream = (new Renderer())->render($player, $terminal, $fps);

        $total = 0;
        $unique = 0;
        $prev = null;

        foreach ($stream as $snapshot) {
            $total++;
            $hash = $this->hashGrid($snapshot);
            fwrite($stdout, sprintf(
                "%.3f\t%d,%d\t%s\n",
                $snapshot->time,
                $snapshot->cursor->row,
                $snapshot->cursor->col,
                $hash,
            ));
            if ($prev === null || !$snapshot->equals($prev)) {
                $unique++;
            }
            $prev = $snapshot;
        }

        // FrameDedup walks the stream again — but FrameStream is a fresh
        // generator on each iteration, so this is safe. We could instead
        // tally inline against `$prev` above; doing it this way keeps the
        // dedup count consistent with the actual encoder pipeline.
        $stream2 = (new Renderer())->render(new Player($cassette), $terminal, $fps);
        $dedupCount = iterator_count(FrameDedup::dedup($stream2));

        fwrite($stdout, sprintf(
            "\nframes: %d  unique: %d  deduped: %d\n",
            $total,
            $unique,
            $dedupCount,
        ));
        return 0;
    }

    /**
     * Deterministic SHA-1 of the grid: walks row-major, emitting
     * `row|col|char|fg|bg|attrs` per cell so two grids agree iff they'd
     * render identically. The cursor is hashed too so blink-only frames
     * still get distinct hashes.
     */
    private function hashGrid(Snapshot $snapshot): string
    {
        $grid = $snapshot->grid;
        $hash = hash_init('sha1');
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->get($r, $c);
                hash_update($hash, sprintf("%d|%d|%s|%d|%d|%d\n", $r, $c, $cell->char, $cell->fg, $cell->bg, $cell->attrs));
            }
        }
        hash_update($hash, sprintf("cursor|%d|%d|%d|%d", $snapshot->cursor->row, $snapshot->cursor->col, $snapshot->cursor->shape, $snapshot->cursor->visible ? 1 : 0));
        return hash_final($hash);
    }

    private function resolveTheme(?string $name): Theme
    {
        return match ($name) {
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
            'Dracula' => Theme::dracula(),
            'SolarizedDark' => Theme::solarizedDark(),
            default => Theme::tokyoNight(),
        };
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
