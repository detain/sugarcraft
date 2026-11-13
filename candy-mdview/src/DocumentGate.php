<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * The ONE document door: `is_file` → regular-file re-check on the opened
 * handle → size cap → capped read → binary refusal, in that order, used by
 * every path bytes take to enter a render — the file argument, piped stdin,
 * and the pager's reload sweep alike. Before this consolidation the sweep
 * bypassed all of it (a 20 MB replacement grew past the "64 MiB contract"
 * into an OOM of the live pager, and an ELF swapped onto the watched path
 * rendered as a success frame), and stdin was an unbounded slurp while the
 * file lane refused the same bytes cleanly.
 *
 * Defence-in-depth note: the control-ratio probe and the whole-string NUL
 * scan are the CONTRACT layer (bad input is refused, exit 2, named). The
 * renderer's stripControls is a separate safety NET that bounds escape
 * damage downstream — a render-safety dependency the gate deliberately does
 * not lean on, and RenderCommand pins `withRawControlChars(false)`
 * explicitly so a future default flip cannot silently reopen either channel.
 *
 * All refusals throw {@see \InvalidArgumentException} with a localized
 * message; callers on the CLI map them to the usage-error exit code, and
 * the reload sweep maps them to "keep the last good frame" policy.
 */
final class DocumentGate
{
    /** Maximum document size accepted through ANY door (file, pipe, sweep). */
    public const MAX_INPUT_BYTES = 67_108_864; // 64 MiB

    /** Head window sampled for the control-character ratio probe. */
    private const BINARY_PROBE_BYTES = 8192;

    /** C0/DEL fraction of the head window that is refusal-grade. */
    private const BINARY_CONTROL_RATIO = 0.05;

    private function __construct()
    {
    }

    /**
     * Read a local document path under the full gate.
     *
     * @throws \InvalidArgumentException on directory, missing/unreadable,
     *                                   over-cap, or binary input
     */
    public static function readFile(string $path): string
    {
        if (is_dir($path) === true) {
            throw new \InvalidArgumentException(Lang::t('render.file_is_directory', ['path' => $path]));
        }
        if (is_file($path) === false) {
            throw new \InvalidArgumentException($path === '' || file_exists($path)
                ? Lang::t('render.file_unreadable', ['path' => $path])
                : Lang::t('render.file_missing', ['path' => $path]));
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException(Lang::t('render.file_unreadable', ['path' => $path]));
        }

        try {
            // The S_ISREG test runs on the OPENED handle, not the earlier
            // path check: a path swapped to a device or symlink-to-device
            // between the checks above and the read below is refused here,
            // and the capped read can never balloon a grow-past-cap race.
            $stat = fstat($handle);
            if ($stat === false || (($stat['mode'] ?? 0) & 0170000) !== 0100000) {
                throw new \InvalidArgumentException(Lang::t('render.file_unreadable', ['path' => $path]));
            }
            if ($stat['size'] > self::MAX_INPUT_BYTES) {
                throw new \InvalidArgumentException(Lang::t('render.input_too_large', [
                    'path'  => $path,
                    'limit' => self::MAX_INPUT_BYTES,
                ]));
            }

            $raw = @stream_get_contents($handle, self::MAX_INPUT_BYTES + 1);
            if (!is_string($raw)) {
                throw new \InvalidArgumentException(Lang::t('render.file_unreadable', ['path' => $path]));
            }
            if (strlen($raw) > self::MAX_INPUT_BYTES) {
                // Grew between fstat and read — refuse, do not consume.
                throw new \InvalidArgumentException(Lang::t('render.input_too_large', [
                    'path'  => $path,
                    'limit' => self::MAX_INPUT_BYTES,
                ]));
            }
        } finally {
            fclose($handle);
        }

        self::refuseBinary($raw, $path);

        return $raw;
    }

    /**
     * Read a piped document stream under the same size and binary contract.
     * The read itself is bounded (MAX+1 bytes), so an endless producer is
     * refused, not slurped.
     *
     * @param resource $stream
     *
     * @throws \InvalidArgumentException on empty, over-cap, or binary input
     */
    public static function readStream($stream, string $label): string
    {
        $raw = @stream_get_contents($stream, self::MAX_INPUT_BYTES + 1);
        if (!is_string($raw) || $raw === '') {
            throw new \InvalidArgumentException(Lang::t('render.no_input_empty'));
        }
        if (strlen($raw) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException(Lang::t('render.input_too_large', [
                'path'  => $label,
                'limit' => self::MAX_INPUT_BYTES,
            ]));
        }

        self::refuseBinary($raw, $label);

        return $raw;
    }

    /**
     * Refuse to render binary payloads. Mistyping onto a PNG/PDF/ELF must
     * not spray escape sequences (possible cursor/color-state changes) at
     * the terminal with a success code. Probe: a NUL byte ANYWHERE in the
     * document is conclusive (the cap already holds it whole and
     * str_contains is a memchr-class scan — the old head-only probe let
     * 9000 printable bytes hide an ELF tail); beyond that, a
     * control-character ratio above 5% of the 8 KiB head window is
     * refusal-grade for anything a human would call a text file (UTF-8
     * text and its multi-byte sequences contain no C0 bytes).
     *
     * @throws \InvalidArgumentException on refusal-grade input
     */
    public static function refuseBinary(string $raw, string $label): void
    {
        if (str_contains($raw, "\x00") === true) {
            throw new \InvalidArgumentException(Lang::t('render.binary_input', ['path' => $label]));
        }

        $probe = substr($raw, 0, self::BINARY_PROBE_BYTES);
        if ($probe !== '' && $probe !== false) {
            $controlCount = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $probe);
            if ($controlCount !== false && $controlCount / strlen($probe) > self::BINARY_CONTROL_RATIO) {
                throw new \InvalidArgumentException(Lang::t('render.binary_input', ['path' => $label]));
            }
        }
    }
}
