<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Lang;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Support\Scalars;
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Terminal;
use SugarCraft\Vterm\Theme;

/**
 * `candy-vcr inspect <cassette|.tape> [--frames]`
 * `[--since=<seconds>] [--until=<seconds>] [--fps=<n>|--fps <n>]`
 *
 * Pretty-prints the events in a cassette: timestamp, kind, and a one-line
 * summary of the payload. Accepts either a JSONL/Relative/Yaml/asciinema
 * cassette or a `.tape` source file (auto-detected via
 * {@see CassetteLoader}).
 *
 * `--frames` switches to the frame timeline view: walks the cassette through
 * a Renderer + Terminal exactly as `render-tape` does, then prints one line
 * per Snapshot with `time<TAB>cursor_row,cursor_col<TAB>grid_sha1`. The
 * grid hash digests `row|col|char|fg|bg|attrs|fgTruecolor|bgTruecolor|rendition`
 * tuples (the two truecolor slots hashing to `-1` when unset) in
 * deterministic row-major order, so two grids producing the same visible
 * output share a hash regardless of cell-construction order — while a
 * differing packed truecolor or line rendition separates them.
 */
final class InspectCommand implements DocumentedCommand
{
    public function summary(): string
    {
        return Lang::t('summary.inspect');
    }

    public function usage(): string
    {
        return Lang::t('cli.usage_label') . ' candy-vcr inspect <cassette|.tape> [--frames]' . "\n"
            . '       [--since=<seconds>] [--until=<seconds>] [--fps=<n>|--fps <n>]' . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = null;
        $since = null;
        $until = null;
        $sinceRaw = null;
        $untilRaw = null;
        $frames = false;
        $fpsRaw = null;
        $fpsMissingValue = false;
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--since=')) {
                $sinceRaw = substr($arg, 8);
            } elseif (str_starts_with($arg, '--until=')) {
                $untilRaw = substr($arg, 8);
            } elseif ($arg === '--fps') {
                // Space form, matching `render-tape --fps <n>` and the kv
                // ArgParser family. A following token starting with `--` is
                // another option, not this one's value.
                if ($i + 1 >= $count || str_starts_with($args[$i + 1], '--')) {
                    $fpsMissingValue = true;
                } else {
                    $fpsRaw = $args[++$i];
                }
            } elseif (str_starts_with($arg, '--fps=')) {
                $raw = substr($arg, 6);
                if ($raw === '') {
                    $fpsMissingValue = true;
                } else {
                    $fpsRaw = $raw;
                }
            } elseif ($arg === '--frames') {
                $frames = true;
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, Lang::t('cli.unknown_option', ['verb' => 'inspect', 'option' => $arg]) . "\n");
                return 2;
            } else {
                $path = $arg;
            }
        }

        if ($fpsMissingValue) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'inspect', 'message' => Lang::t('inspect.fps_requires_value')]) . "\n");
            return 2;
        }
        // `--since=abc` used to cast to 0.0 through (float) and silently
        // show the whole cassette — a window filter that quietly becomes no
        // filter. The flag takes a non-negative finite number or the verb
        // refuses at the same rc-2 door as the other misuse shapes.
        if ($sinceRaw !== null) {
            if (!is_numeric($sinceRaw) || !is_finite((float) $sinceRaw) || (float) $sinceRaw < 0.0) {
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'inspect', 'message' => Lang::t('inspect.since_invalid')]) . "\n");
                return 2;
            }
            $since = (float) $sinceRaw;
        }
        if ($untilRaw !== null) {
            if (!is_numeric($untilRaw) || !is_finite((float) $untilRaw) || (float) $untilRaw < 0.0) {
                fwrite($stderr, Lang::t('cli.err', ['verb' => 'inspect', 'message' => Lang::t('inspect.until_invalid')]) . "\n");
                return 2;
            }
            $until = (float) $untilRaw;
        }
        try {
            // Single validation door shared with render-tape (FpsOption):
            // non-numeric, zero, negative and non-finite all fail here with
            // the same message, whatever spelling of the option was used.
            $parsedFps = $fpsRaw === null ? null : FpsOption::parse($fpsRaw);
        } catch (\InvalidArgumentException $e) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'inspect', 'message' => $e->getMessage()]) . "\n");
            return 2;
        }
        $fps = $parsedFps ?? 30.0;

        if ($path === null) {
            fwrite($stderr, $this->usage());
            return 2;
        }

        $loader = new CassetteLoader();
        try {
            $cassette = $loader->load($path);
        } catch (\Throwable $e) {
            fwrite($stderr, Lang::t('cli.err', ['verb' => 'inspect', 'message' => $e->getMessage()]) . "\n");
            return 1;
        }
        CassetteLoader::writeTapeWarnings($loader, $stderr, 'inspect');

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
        fwrite($stdout, "\n" . Lang::t('inspect.shown', ['shown' => $shown, 'total' => $cassette->eventCount()]) . "\n");
        return 0;
    }

    /**
     * Walk the cassette through a Renderer + Terminal at `$fps` and print
     * one line per snapshot. Closes with a `frames: <total> unique: <dedup>
     * deduped: <count>` footer — uniqueness is measured by consecutive
     * {@see Snapshot::equals()} comparison in the stream (the same rule
     * {@see \SugarCraft\Vcr\Render\FrameDedup} applies), so the dedup ratio
     * is visible at a glance.
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
        $dedupCount = 0;
        $prev = null;
        $prevUnique = null;

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
                // Track consecutive unique snapshots for dedup count
                if ($prevUnique === null || !$snapshot->equals($prevUnique)) {
                    $dedupCount++;
                }
                $prevUnique = $snapshot;
            }
            $prev = $snapshot;
        }

        fwrite($stdout, "\n" . Lang::t('inspect.frames_footer', [
            'total' => $total,
            'unique' => $unique,
            'deduped' => $dedupCount,
        ]) . "\n");
        return 0;
    }

    /**
     * Deterministic SHA-1 of the grid: walks row-major, emitting
     * `row|col|char|fg|bg|attrs|fgTruecolor|bgTruecolor|rendition` per cell so
     * two grids agree iff they'd render identically. The 24-bit slots and the
     * line rendition are folded in so a truecolor repaint or a DECDHL/DECDWL
     * stamp changes the hash even when the glyph and palette numbers are
     * unchanged (`-1` marks a palette/default slot with no packed RGB). The
     * cursor is hashed too so blink-only frames still get distinct hashes.
     */
    private function hashGrid(Snapshot $snapshot): string
    {
        $grid = $snapshot->grid;
        $hash = hash_init('sha1');
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->cell($r, $c);
                hash_update($hash, sprintf(
                    "%d|%d|%s|%d|%d|%d|%d|%d|%d\n",
                    $r,
                    $c,
                    $cell->char,
                    $cell->fg,
                    $cell->bg,
                    $cell->attrs,
                    $cell->fgTruecolor ?? -1,
                    $cell->bgTruecolor ?? -1,
                    $cell->rendition->value,
                ));
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
        fwrite($stdout, Lang::t('inspect.header', [
            'version' => $cassette->header->version,
            'cols' => $cassette->header->cols,
            'rows' => $cassette->header->rows,
            'runtime' => $this->displaySafe($cassette->header->runtime),
            'created' => $this->displaySafe($cassette->header->createdAt),
            'duration' => sprintf('%.3f', $cassette->duration()),
            'events' => $cassette->eventCount(),
        ]) . "\n");
        fwrite($stdout, str_repeat('-', 72) . "\n");
    }

    private function formatEvent(\SugarCraft\Vcr\Event $event): string
    {
        $base = sprintf('  t=%.3fs  %-7s', $event->t, $event->kind->value);
        return $base . '  ' . match ($event->kind) {
            EventKind::Resize => sprintf(
                '%dx%d',
                Scalars::int($event->payload['cols'] ?? 0, 'inspect resize cols'),
                Scalars::int($event->payload['rows'] ?? 0, 'inspect resize rows'),
            ),
            EventKind::Output => $this->summarizeBytes(Scalars::string($event->payload['b'] ?? '', 'inspect output bytes')),
            EventKind::Input => isset($event->payload['msg'])
                ? $this->formatMsgTag($event->payload['msg'])
                : $this->summarizeBytes(Scalars::string($event->payload['b'] ?? '', 'inspect input bytes')),
            EventKind::Quit => '',
            // The path comes off the cassette; a forged snapshot event could
            // carry ANSI/OSC bytes that would be emitted raw to the host.
            EventKind::Snapshot => Lang::t('inspect.screenshot', ['path' => $this->displaySafe(
                Scalars::string($event->payload['path'] ?? '?', 'inspect snapshot path')
            )]),
            EventKind::Hide => '',
            EventKind::Show => '',
        };
    }

    /**
     * `@<type>` label for an input envelope. A non-array `msg` (malformed
     * tape) degrades to `@?` exactly as nested null-coalescing did.
     */
    private function formatMsgTag(mixed $msg): string
    {
        if (!is_array($msg)) {
            return '@?';
        }
        return '@' . $this->displaySafe(Scalars::string($msg['@type'] ?? '?', 'inspect msg @type'));
    }

    /**
     * Replace every non-printable byte with '.' so cassette-carried strings
     * (paths, @type labels) can never smuggle escape sequences to the host
     * through `inspect`. Same shape as the byte previews in summarizeBytes().
     */
    private function displaySafe(string $text): string
    {
        return preg_replace('/[^\x20-\x7e]/', '.', $text) ?? '';
    }

    private function summarizeBytes(string $bytes): string
    {
        $len = strlen($bytes);
        $printable = preg_replace('/[^\x20-\x7e]/', '.', $bytes) ?? '';
        $shown = strlen($printable) > 40 ? substr($printable, 0, 40) . '...' : $printable;
        return sprintf('%d bytes  %s', $len, $shown);
    }
}
