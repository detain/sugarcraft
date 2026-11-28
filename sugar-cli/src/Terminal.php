<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\WidthProbe;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Tty\Backend;

/**
 * What a CLI can learn about the terminal it writes to.
 *
 * Original work: no charmbracelet upstream exposes this; fang reads the
 * width through charmbracelet/x/term.
 */
final class Terminal
{
    private function __construct() {}

    /**
     * The column count to lay output for `$stream` out at, or null when
     * nothing credible answers.
     *
     * Two arms, the exported one first:
     *
     * 1. `COLUMNS` as the environment exports it, accepted in 1 to 10,000 —
     *    the same read the presenters' `AutoWidth::Auto` makes. When a
     *    harness or a user exports a width, that is the answer they asked
     *    for, whatever the stream is.
     * 2. Only when nothing credible was exported, and only when `$stream` is
     *    a terminal: the live window size from sugar-core's {@see Tty}
     *    (the `TIOCGWINSZ` ioctl first). bash keeps COLUMNS unexported, so
     *    on a plain interactive terminal this arm is usually the one that
     *    answers.
     *
     * A pipe, a file or `php://memory` therefore returns null unless COLUMNS
     * is exported: the probe never measures the controlling terminal behind
     * a redirected stream, so piped output is not re-flowed to a window its
     * reader never sees. The presenters do not call this — their
     * `AutoWidth::Auto` stays environment-only and device-free; call this
     * where a real measurement is wanted and pass the result as `$width`.
     *
     * @param resource|null $stream the output stream; null means STDOUT (and
     *        null comes back when STDOUT is undefined or closed)
     * @throws \InvalidArgumentException when `$stream` is neither null nor a
     *         stream resource
     */
    public static function columns(mixed $stream = null): ?int
    {
        if ($stream !== null && !is_resource($stream) && get_debug_type($stream) !== 'resource (closed)') {
            throw new \InvalidArgumentException('Terminal::columns() expects a stream resource or null; got ' . get_debug_type($stream) . '.');
        }

        $exported = WidthProbe::columns();
        if ($exported !== null) {
            return $exported;
        }

        $stream ??= defined('STDOUT') ? STDOUT : null;
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || !@stream_isatty($stream)) {
            return null;
        }

        try {
            $size = (new Tty($stream))->size();
        } catch (\Throwable) {
            return null;
        }

        $columns = (int) ($size['cols'] ?? 0);
        // sugar-core answers a fixed last-resort size when every probe failed;
        // that is a guess, not a measurement, so it does not count here (a
        // real window of exactly that size reads as unknown, the cheaper error).
        if ($size === Backend::LAST_RESORT_SIZE || $columns < 1 || $columns > WidthProbe::MAX_CREDIBLE) {
            return null;
        }
        return $columns;
    }
}
