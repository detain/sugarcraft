<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\Util\Tty;

/**
 * Best-effort terminal geometry for start-up sizing decisions.
 *
 * Resolution order (first hit wins):
 *  1. an explicit {@see self::override()} — tests and embedders;
 *  2. the COLUMNS / LINES environment, when exported and positive;
 *  3. an ioctl against STDOUT (or STDIN when only it is a terminal);
 *  4. null — nothing trustworthy. Callers fall back to their historical
 *     literals (lists 60 cells, pager 80x20) so piped/cron contexts keep
 *     the byte-stable output they were pinned with.
 *
 * Deliberately NOT memoised: a Program re-probes cheaply per command and a
 * SIGWINCH must not be poisoned by a start-up snapshot.
 */
final class Geometry
{
    /** @var array{cols:int, rows:int}|null */
    private static ?array $override = null;

    /**
     * Pin the reported size for the current process (null clears it).
     *
     * @param array{cols:int, rows:int}|null $size
     */
    public static function override(?array $size): void
    {
        self::$override = $size;
    }

    public static function columnsOrNull(): ?int
    {
        return self::size()['cols'] ?? null;
    }

    public static function rowsOrNull(): ?int
    {
        return self::size()['rows'] ?? null;
    }

    /**
     * @return array{cols?:int, rows?:int}
     */
    public static function size(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $size = [];
        $columns = self::positiveInt(getenv('COLUMNS'));
        if ($columns !== null) {
            $size['cols'] = $columns;
        }
        $lines = self::positiveInt(getenv('LINES'));
        if ($lines !== null) {
            $size['rows'] = $lines;
        }
        if ($size !== []) {
            return $size;
        }

        $probed = self::probe();
        if ($probed !== null) {
            return $probed;
        }

        return $size;
    }

    /**
     * @return array{cols:int, rows:int}|null
     */
    private static function probe(): ?array
    {
        $stream = InteractiveRuntime::stdoutIsTerminal()
            ? (defined('STDOUT') ? STDOUT : null)
            : (InteractiveRuntime::stdinIsTerminal() ? (defined('STDIN') ? STDIN : null) : null);
        if ($stream === null) {
            return null;
        }
        try {
            $size = (new Tty($stream))->size();
        } catch (\Throwable) {
            return null;
        }
        $cols = self::positiveInt($size['cols'] ?? null);
        $rows = self::positiveInt($size['rows'] ?? null);
        if ($cols === null && $rows === null) {
            return null;
        }
        $out = [];
        if ($cols !== null) {
            $out['cols'] = $cols;
        }
        if ($rows !== null) {
            $out['rows'] = $rows;
        }
        return $out;
    }

    private static function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;
        return $value > 0 ? $value : null;
    }
}
