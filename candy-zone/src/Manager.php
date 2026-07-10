<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Sentinel;

/**
 * Mouse-zone manager. Wraps content with invisible zone markers identifying
 * a logical "zone"; {@see scan()} later finds those markers, records each
 * zone's bounding box in terminal cells, and returns the cleaned-up frame.
 *
 * Marker + bounding-box computation are delegated to candy-mouse, the shared
 * hit-test primitive (single source of truth for the wire format across the
 * SugarCraft tree). Markers are the private-use-area sentinel triples emitted
 * by {@see \SugarCraft\Mouse\Mark::wrap()}:
 *
 *   open:  U+E000 <id> U+E001
 *   close: U+E000 /<id> U+E001
 *
 * U+E000 / U+E001 are invisible and never collide with visible text or ANSI
 * escape sequences, so they don't affect layout. Ids must match candy-mouse's
 * charset `^[A-Za-z0-9._:-]+$` (letters, digits, and `._:-`) — an id carrying
 * a sentinel/control/whitespace byte would desync scanning and is rejected by
 * {@see mark()} with an \InvalidArgumentException. The combined
 * `prefix() . $id` is what gets validated.
 *
 * Zones discovered during the most recent {@see scan()} replace any
 * previously known zones for the same id (other ids persist).
 */
final class Manager
{
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
     * from a monotonic counter. The generated prefix uses the
     * candy-mouse-safe charset (`<n>-`), so `prefix() . $id` stays valid.
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

    /**
     * Toggle mouse motion reporting.
     *
     * When `$on` is true, returns the escape sequence that enables
     * SGR Mouse mode 1003 (all motion events reported on movement).
     * When false, returns the sequence that disables it.
     *
     * The caller writes the returned string to the terminal to
     * activate/deactivate motion reporting. This manager does not
     * directly emit to the TTY — it is a text-processing component.
     * This is unrelated to the zone markers.
     *
     * Mirrors bubblezone's `Manager::SetMotionTracking`.
     */
    public function setMotionTracking(bool $on): string
    {
        // CSI ? 1003 h = enable all event mode
        // CSI ? 1003 l = disable
        return $on ? "\x1b[?1003h" : "\x1b[?1003l";
    }

    /** Read-only accessor for the prefix this manager prepends to ids. */
    public function prefix(): string
    {
        return $this->idPrefix;
    }

    /**
     * Wrap $content with start/end markers for `prefix() . $id`.
     *
     * Delegates the marker encoding to {@see \SugarCraft\Mouse\Mark::wrap()}
     * so the wire format lives in exactly one place. No-op (returns
     * $content verbatim) when the manager is disabled.
     *
     * @throws \InvalidArgumentException When `prefix() . $id` contains a byte
     *         outside candy-mouse's `^[A-Za-z0-9._:-]+$` charset (validated by
     *         Mark::wrap). candy-zone's own prefixes and real consumer ids
     *         (`tab-N`, `cell:N`, `item:N`) already comply.
     */
    public function mark(string $id, string $content): string
    {
        if (!$this->enabled) {
            return $content;
        }
        return Mark::zone($this->idPrefix . $id, $content);
    }

    /**
     * Strip markers from $rendered, recording each zone's bounding box,
     * and return the cleaned frame ready for the terminal.
     *
     * Bounding-box computation is delegated to {@see \SugarCraft\Mouse\Scan}
     * (CJK-width aware via candy-core's Width), then the discovered zones are
     * upserted into this manager's registry as {@see Zone} adapters — same id,
     * same bounds. The registry merges rather than replaces: ids seen this
     * scan overwrite prior entries; other ids persist.
     *
     * No-op when {@see setEnabled()} has flipped the manager off — the input
     * passes through unchanged and no zones are recorded.
     */
    public function scan(string $rendered): string
    {
        if (!$this->enabled) {
            return $rendered;
        }

        // candy-mouse is the single source of truth for parsing + bounds.
        foreach ((new Scan())->parse($rendered) as $id => $mouseZone) {
            $this->zones[$id] = Zone::fromMouseZone($mouseZone);
        }

        return self::stripMarkers($rendered);
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

    /**
     * Forget zone state. With no argument, drops every recorded zone
     * (the original `clear()` shape, kept for back-compat). Pass an
     * `$id` to drop a single zone — equivalent to the upstream
     * `Manager::Clear(id string)` overload.
     */
    public function clear(?string $id = null): void
    {
        if ($id === null) {
            $this->zones = [];
            return;
        }
        unset($this->zones[$this->idPrefix . $id]);
    }

    /**
     * Tear down the manager. Mirrors bubblezone's `Manager::Close()` —
     * upstream stops the background `zoneWorker` goroutine here. Our
     * port computes everything synchronously inside `scan()` so there
     * is no worker to stop, but `close()` still drops every recorded
     * zone and disables the manager so subsequent `mark()` / `scan()`
     * calls become pass-throughs. Idempotent.
     */
    public function close(): void
    {
        $this->zones = [];
        $this->enabled = false;
    }

    /**
     * Walk the recorded zones and return the innermost (smallest-area) one
     * whose bounds contain `$mouse`. When zones nest, the smallest one wins;
     * on equal area the last-inserted zone takes priority. Returns null if
     * no zone matches (or if the Msg isn't a {@see MouseMsg} to begin with —
     * handy when models blanket-route every Msg through this helper).
     *
     * Mirrors bubblezone's `Manager::AnyInBounds()`.
     */
    public function anyInBounds(Msg $mouse): ?Zone
    {
        if (!$mouse instanceof MouseMsg) {
            return null;
        }
        $hit = null;
        $smallestArea = PHP_INT_MAX;
        foreach ($this->zones as $zone) {
            if ($zone->inBounds($mouse)) {
                $area = $zone->width() * $zone->height();
                if ($area < $smallestArea) {
                    $smallestArea = $area;
                    $hit = $zone;
                }
            }
        }
        return $hit;
    }

    /**
     * Hit-test `$mouse` against every recorded zone. If a zone matches,
     * dispatch a {@see MsgZoneInBounds} carrying the hit zone + the
     * original Msg through `$model->update()` and return the resulting
     * pair `[Model, ?Cmd]`. If nothing matches, dispatch the original
     * Msg verbatim.
     *
     * Mirrors bubblezone's `Manager::AnyInBoundsAndUpdate()` — the
     * idiomatic dispatch helper for routing a click to whichever
     * sub-component owns the hit area without writing an explicit
     * dispatch table.
     *
     * @return array{0:Model,1:?\Closure}
     */
    public function anyInBoundsAndUpdate(Model $model, Msg $mouse): array
    {
        $zone = $this->anyInBounds($mouse);
        if ($zone !== null && $mouse instanceof MouseMsg) {
            return $model->update(new MsgZoneInBounds($zone, $mouse));
        }
        return $model->update($mouse);
    }

    /**
     * Remove candy-mouse's PUA sentinel tags from a rendered frame, leaving
     * the visible content byte-for-byte intact.
     *
     * Each tag is a `U+E000 … U+E001` span (open: `U+E000 <id> U+E001`,
     * close: `U+E000 /<id> U+E001`); the content BETWEEN an open and its
     * matching close is preserved. This mirrors the marker grammar walked by
     * {@see \SugarCraft\Mouse\Scan::parse()} so the cleaned display string
     * and the scanned bounds stay in lock-step. An unterminated open sentinel
     * drops only its 3 sentinel bytes (Scan tolerates the same); a stray
     * close sentinel is likewise dropped (Scan treats it as a zero-width,
     * non-content byte).
     */
    private static function stripMarkers(string $rendered): string
    {
        // Fast path — no sentinels present means nothing to strip.
        if (!str_contains($rendered, Sentinel::OPEN) && !str_contains($rendered, Sentinel::CLOSE)) {
            return $rendered;
        }

        $out      = '';
        $len      = strlen($rendered);
        $i        = 0;
        $runStart = 0;
        while ($i < $len) {
            if ($rendered[$i] === "\xEE" && ($rendered[$i + 1] ?? '') === "\x80") {
                $third = $rendered[$i + 2] ?? '';

                // U+E000 open sentinel (EE 80 80) — start of an open/close tag.
                if ($third === "\x80") {
                    $out .= substr($rendered, $runStart, $i - $runStart);
                    $end  = strpos($rendered, Sentinel::CLOSE, $i + 3);
                    if ($end === false) {
                        // Unterminated tag — drop the 3 sentinel bytes only.
                        $i += 3;
                        $runStart = $i;
                        continue;
                    }
                    $i        = $end + strlen(Sentinel::CLOSE);
                    $runStart = $i;
                    continue;
                }

                // Stray U+E001 close sentinel (EE 80 81) with no opener.
                if ($third === "\x81") {
                    $out .= substr($rendered, $runStart, $i - $runStart);
                    $i += 3;
                    $runStart = $i;
                    continue;
                }
            }
            $i++;
        }
        $out .= substr($rendered, $runStart);
        return $out;
    }
}
