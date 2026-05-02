<?php

declare(strict_types=1);

namespace CandyCore\Zone;

use CandyCore\Core\Util\Width;

/**
 * Mouse-zone manager. Wraps content with APC escape sequences identifying
 * a logical "zone"; {@see scan()} later finds those markers, records each
 * zone's bounding box in terminal cells, and returns the cleaned-up frame.
 *
 * Marker format (terminal-ignored):
 *
 *   start: ESC _ "candyzone:S:<id>" ESC \
 *   end  : ESC _ "candyzone:E:<id>" ESC \
 *
 * Zones discovered during the most recent {@see scan()} replace any
 * previously known zones for the same id.
 */
final class Manager
{
    private const APC_PREFIX = "\x1b_";
    private const APC_ST     = "\x1b\\";
    private const TAG_START  = 'candyzone:S:';
    private const TAG_END    = 'candyzone:E:';

    /** @var array<string, Zone> */
    private array $zones = [];
    private bool $enabled = true;
    private string $idPrefix = '';
    /** Class-level counter that gives every prefix-bearing manager a unique tag. */
    private static int $prefixCounter = 0;

    public static function newGlobal(): self
    {
        return new self();
    }

    /**
     * Build a manager that namespaces every id with a unique prefix.
     *
     * Useful when you compose multiple CandyZone-aware components into
     * the same Program — each component grabs its own prefixed manager
     * so two `ItemList`s using the literal id `"item-0"` don't collide.
     *
     * Mirrors bubblezone's `NewPrefix`. Pass an explicit `$prefix` to
     * fix the namespace; omit (or pass empty) to auto-generate one
     * from a monotonic counter.
     */
    public static function newPrefix(string $prefix = ''): self
    {
        $m = new self();
        $m->idPrefix = $prefix !== ''
            ? $prefix
            : (string) (++self::$prefixCounter) . '-';
        return $m;
    }

    /**
     * Toggle marker emission and scanning. When `$enabled = false`,
     * `mark()` returns `$content` verbatim (no markers wrapped) and
     * `scan()` is a no-op pass-through. Useful for non-interactive
     * output (CI logs, file dumps) where the markers add nothing.
     *
     * Mirrors bubblezone's `SetEnabled`.
     */
    public function setEnabled(bool $on): void
    {
        $this->enabled = $on;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Read-only accessor for the prefix this manager prepends to ids. */
    public function prefix(): string
    {
        return $this->idPrefix;
    }

    /** Wrap $content with start/end markers for $id. */
    public function mark(string $id, string $content): string
    {
        if (!$this->enabled) {
            return $content;
        }
        $fullId = $this->idPrefix . $id;
        return self::APC_PREFIX . self::TAG_START . $fullId . self::APC_ST
             . $content
             . self::APC_PREFIX . self::TAG_END . $fullId . self::APC_ST;
    }

    /**
     * Strip markers from $rendered, recording each zone's bounding box.
     * Returns the cleaned frame ready for the terminal.
     *
     * No-op when {@see setEnabled()} has flipped the manager off —
     * the input passes through unchanged.
     */
    public function scan(string $rendered): string
    {
        if (!$this->enabled) {
            return $rendered;
        }
        $clean = '';
        $row = 1;
        $col = 1;
        /** @var array<string, array{int,int,int,int}> $starts id => [startCol,startRow,maxCol,maxRow] */
        $open = [];

        $len = strlen($rendered);
        $i = 0;
        while ($i < $len) {
            $b = $rendered[$i];

            // APC zone marker?
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '_') {
                $end = strpos($rendered, self::APC_ST, $i + 2);
                if ($end === false) {
                    // Unterminated APC; leave the rest alone.
                    $clean .= substr($rendered, $i);
                    break;
                }
                $payload = substr($rendered, $i + 2, $end - $i - 2);
                if (str_starts_with($payload, self::TAG_START)) {
                    $id = substr($payload, strlen(self::TAG_START));
                    $open[$id] = [$col, $row, $col, $row];
                } elseif (str_starts_with($payload, self::TAG_END)) {
                    $id = substr($payload, strlen(self::TAG_END));
                    if (isset($open[$id])) {
                        [$startCol, $startRow, $maxCol, $maxRow] = $open[$id];
                        // End marker sits *after* the last visible cell;
                        // back the end up by one.
                        $endCol = max($startCol, $col - 1);
                        $endRow = $row;
                        // If we wrapped to a new line, prefer the end of the
                        // previous row for the rightmost column.
                        if ($endRow > $startRow && $col === 1) {
                            $endCol = $maxCol;
                            $endRow = $row - 1;
                        } else {
                            $endCol = max($endCol, $maxCol);
                            $endRow = max($endRow, $maxRow);
                        }
                        $this->zones[$id] = new Zone($id, $startCol, $startRow, $endCol, $endRow);
                        unset($open[$id]);
                    }
                }
                // else: unknown APC payload, drop it silently.
                $i = $end + strlen(self::APC_ST);
                continue;
            }

            // CSI sequence: pass through unchanged, no width.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = ord($rendered[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $clean .= substr($rendered, $i, $j - $i);
                $i = $j;
                continue;
            }

            // OSC sequence: pass through unchanged, terminate on BEL or ST.
            if ($b === "\x1b" && ($rendered[$i + 1] ?? '') === ']') {
                $j = $i + 2;
                while ($j < $len) {
                    if ($rendered[$j] === "\x07") { $j++; break; }
                    if ($rendered[$j] === "\x1b" && ($rendered[$j + 1] ?? '') === '\\') { $j += 2; break; }
                    $j++;
                }
                $clean .= substr($rendered, $i, $j - $i);
                $i = $j;
                continue;
            }

            if ($b === "\n") {
                // Mark each open zone as having reached the rightmost
                // column of the current row before we move on.
                foreach ($open as $id => $bounds) {
                    [$sCol, $sRow, $maxCol, $maxRow] = $bounds;
                    $open[$id] = [$sCol, $sRow, max($maxCol, $col - 1), max($maxRow, $row)];
                }
                $clean .= $b;
                $row++;
                $col = 1;
                $i++;
                continue;
            }

            // Plain visible character — measure its grapheme width and
            // honour zero-width clusters (ZWJ, combining marks). Clamping
            // those to 1 cell would inflate zone widths and drift later
            // zones, breaking inBounds() / pos() calculations.
            $cluster = self::nextGrapheme($rendered, $i);
            $col    += Width::string($cluster);
            $clean  .= $cluster;
            $i      += strlen($cluster);
        }

        return $clean;
    }

    public function get(string $id): ?Zone
    {
        return $this->zones[$this->idPrefix . $id] ?? null;
    }

    /** @return array<string, Zone> */
    public function all(): array
    {
        return $this->zones;
    }

    public function clear(): void
    {
        $this->zones = [];
    }

    /**
     * Return the next grapheme cluster starting at byte offset $i.
     * Falls back to UTF-8 byte parsing when the intl extension is missing.
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
