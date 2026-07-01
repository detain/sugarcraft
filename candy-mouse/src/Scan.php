<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Sentinel;

/**
 * Parse zone sentinels from a rendered string and compute bounding boxes
 * for each discovered zone.
 *
 * Mirrors bubblezone's Scan function — computes bounding boxes by walking
 * the terminal cell grid and honouring CJK wide-char column accounting
 * via {@see Width::string()}.
 *
 * This class is stateless — create a fresh instance per scan pass.
 *
 * Design note — streaming/chunked parsing: for large terminals or
 * streaming renderers, a ScanIterator (implementing IteratorAggregate)
 * could yield zones incrementally as each chunk is scanned, avoiding
 * a full in-memory parse of the entire buffer.  The current Scan class
 * is suitable for single-shot scans; factor chunk support out if needed.
 */
final class Scan
{
    /** @var array<string, array{int,int,int,int}> id => [startCol,startRow,maxCol,maxRow] */
    private array $open = [];

    /** @var array<string, Zone> */
    private array $zones = [];

    /**
     * Feed a rendered string through the scanner and return all discovered
     * zones.
     *
     * @param string $rendered The rendered string containing zone sentinels.
     * @param int|null $width  When provided, endCol is clamped to this
     *                         terminal width.  Useful when rendering in a
     *                         known viewport to prevent zones from extending
     *                         past the visible area.  null = no clamp (default).
     *
     * @return array<string, Zone> id => Zone
     */
    public function parse(string $rendered, ?int $width = null): array
    {
        $this->open  = [];
        $this->zones = [];
        $row   = 1;
        $col   = 1;
        $len   = strlen($rendered);
        $i     = 0;

        while ($i < $len) {
            $b = $rendered[$i];

            // Check for U+E000 sentinel (zone boundary marker).
            if ($b === "\xEE" && $i + 2 < $len && $rendered[$i + 1] === "\x80") {
                $byte3 = $rendered[$i + 2];

                // U+E001 close sentinel (EE 80 81) — id terminator.
                if ($byte3 === "\x81") {
                    $i += 3;
                    continue;
                }

                // U+E000 open sentinel (EE 80 80) — zone boundary.
                if ($byte3 === "\x80") {
                    // Determine whether this is a close sentinel (has '/' after).
                    $isClose = ($i + 3 < $len) && ($rendered[$i + 3] === '/');

                    // Find the next U+E001 to locate the end of the id field.
                    $idEnd = strpos($rendered, Sentinel::CLOSE, $i + 3);
                    if ($idEnd === false) {
                        $i += 3;
                        continue;
                    }

                    // Extract id: skip past '/' for close sentinels.
                    $idStart = $isClose ? $i + 4 : $i + 3;
                    $id = substr($rendered, $idStart, $idEnd - $idStart);

                    if ($isClose) {
                        if (isset($this->open[$id])) {
                            [$startCol, $startRow, $maxCol, $maxRow] = $this->open[$id];
                            // End marker sits after the last visible cell;
                            // back the end up by one column.
                            $endCol = max($startCol, $col - 1);
                            $endRow = $row;
                            if ($endRow > $startRow && $col === 1) {
                                $endCol = $maxCol;
                                $endRow = $row - 1;
                            } else {
                                $endCol = max($endCol, $maxCol);
                                $endRow = max($endRow, $maxRow);
                            }
                            if ($width !== null) {
                                $endCol = min($endCol, $width);
                            }
                            $this->zones[$id] = new Zone($id, $startCol, $startRow, $endCol, $endRow);
                            unset($this->open[$id]);
                        }
                        $i = $idEnd + 3;
                        continue;
                    }

                    // Opening sentinel: record start position.
                    $this->open[$id] = [$col, $row, $col, $row];
                    $i = $idEnd + 3;
                    continue;
                }
            }

            // CSI sequence — pass through with no width.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($rendered[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $i = $j;
                continue;
            }

            // OSC sequence — pass through with no width.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($rendered[$j] === "\x07") { $j++; break; }
                    if ($j + 1 < $len && $rendered[$j] === "\x1b" && $rendered[$j + 1] === '\\') { $j += 2; break; }
                    $j++;
                }
                $i = $j;
                continue;
            }

            // Newline — advance row, reset column; extend open zones to
            // the end of the current line before moving on.
            if ($b === "\n") {
                foreach ($this->open as $id => $bounds) {
                    [$sCol, $sRow, $maxCol, $maxRow] = $bounds;
                    $colAtEol = $width !== null ? min($col - 1, $width) : $col - 1;
                    $this->open[$id] = [$sCol, $sRow, max($maxCol, $colAtEol), max($maxRow, $row)];
                }
                $row++;
                $col = 1;
                $i++;
                continue;
            }

            // Plain visible character — measure display width and advance.
            $cluster = self::nextGrapheme($rendered, $i);
            $col    += Width::string($cluster);
            $i      += strlen($cluster);
        }

        return $this->zones;
    }

    /**
     * Return the next grapheme cluster starting at byte offset $i.
     *
     * Edge case: grapheme_extract() can return an empty string '' when
     * the offset $i lands mid-grapheme (e.g., in the middle of a combine
     * sequence).  The fallback UTF-8 byte scan handles this by returning
     * the next valid multi-byte sequence starting at $i.  This can produce
     * a grapheme that is technically a continuation of a sequence started
     * before $i, but in terminal output this is unlikely to cause issues
     * since combining characters are normally printed after their base.
     */
    private static function nextGrapheme(string $s, int $i): string
    {
        if (function_exists('grapheme_extract')) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (is_string($cluster) && $cluster !== '') {
                return $cluster;
            }
        }
        $b = ord($s[$i]);
        $bytes = match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
        return substr($s, $i, $bytes);
    }
}
