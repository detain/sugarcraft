<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * `candy-vcr replay <cassette> [--speed=instant|realtime] [--idle-trim=N]`
 *
 * Stream the cassette's recorded output bytes to stdout. With
 * `--speed=realtime`, sleeps between events so playback matches the
 * recorded cadence (useful for visual inspection and demos).
 *
 * `--idle-trim=N` clamps inter-event gaps longer than N seconds to N,
 * so long pauses in the recording don't produce long pauses in replay.
 * Use `--no-trim` to restore original (pre-trim) wall-clock timing.
 *
 * No Program is involved — this just plays back what was recorded.
 * For round-trip-replay-into-a-fresh-Program use the
 * {@see \SugarCraft\Vcr\Player} class from PHP code.
 */
final class ReplayCommand implements Command
{
    public function summary(): string
    {
        return "Stream a cassette's recorded output to stdout";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = null;
        $speed = 'instant';
        $noTrim = false;
        $idleTrim = null;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--speed=')) {
                $speed = substr($arg, 8);
            } elseif ($arg === '--no-trim') {
                $noTrim = true;
            } elseif (str_starts_with($arg, '--idle-trim=')) {
                $idleTrim = (float) substr($arg, 12);
                if ($idleTrim <= 0.0) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim must be > 0 seconds\n");
                    return 2;
                }
            } elseif ($arg === '--idle-trim') {
                $next = $args[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim requires a positive seconds argument\n");
                    return 2;
                }
                $i++;
                $idleTrim = (float) $next;
                if ($idleTrim <= 0.0) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim must be > 0 seconds\n");
                    return 2;
                }
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr replay: unknown option {$arg}\n");
                return 2;
            } else {
                $path = $arg;
            }
        }

        if ($path === null) {
            fwrite($stderr, "usage: candy-vcr replay <cassette> [--speed=instant|realtime] [--idle-trim N] [--no-trim]\n");
            return 2;
        }
        if (!in_array($speed, ['instant', 'realtime'], true)) {
            fwrite($stderr, "candy-vcr replay: --speed must be 'instant' or 'realtime'\n");
            return 2;
        }

        try {
            $cassette = (new JsonlFormat())->read($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr replay: {$e->getMessage()}\n");
            return 1;
        }

        $previousT = 0.0;
        foreach ($cassette->events as $event) {
            // P6.5.3 dual-timestamp replay: when --no-trim is passed
            // and the event carries `tRaw` (recorded with --idle-trim),
            // honour the uncompressed timestamp so the playback cadence
            // matches the original wallclock. Default replay (no flag)
            // uses `t` (compressed) which matches existing behaviour.
            $effectiveT = ($noTrim && isset($event->payload['tRaw']))
                ? (float) $event->payload['tRaw']
                : $event->t;
            if ($speed === 'realtime') {
                $delta = $effectiveT - $previousT;
                // Apply idle-trim: clamp long delays to the specified threshold
                // so playback doesn't wait for multi-second gaps that were
                // only present in the recording environment.
                if ($idleTrim !== null && $delta > $idleTrim) {
                    $delta = $idleTrim;
                }
                if ($delta > 0) {
                    usleep((int) ($delta * 1_000_000));
                }
            }
            if ($event->kind === EventKind::Output) {
                fwrite($stdout, (string) ($event->payload['b'] ?? ''));
            }
            $previousT = $effectiveT;
        }
        return 0;
    }
}
