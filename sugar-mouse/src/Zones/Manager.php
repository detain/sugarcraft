<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;

/**
 * Mouse-zone manager. Wraps content with sentinel-tagged zone markers
 * identifying a logical "zone"; {@see scan()} later finds those markers,
 * records each zone's bounding box in terminal cells, and returns the
 * cleaned-up frame.
 *
 * Marker + bounding-box computation are delegated to this package's
 * primitive layer — {@see \SugarCraft\Mouse\Mark} and
 * {@see \SugarCraft\Mouse\Scan} (single source of truth for the wire format
 * across the SugarCraft tree). Markers are the private-use-area sentinel
 * tag pairs emitted by {@see \SugarCraft\Mouse\Mark::zone()} (encoding in
 * {@see \SugarCraft\Mouse\Mark::wrap()}):
 *
 *   open:  U+E000 <id> U+E001
 *   close: U+E000 /<id> U+E001
 *
 * The sentinels cannot appear in ANSI escape sequences or ASCII text, but
 * they do live in the private-use area shared with pasted foreign glyphs —
 * the id charset below and the lone-sentinel rule in {@see \SugarCraft\Mouse\Scan}
 * are what keep foreign PUA bytes from forging a zone; sweeping untrusted
 * content is the consumer's duty (see {@see \SugarCraft\Mouse\Sentinel}).
 * Ids must match the primitive's charset `\A[A-Za-z0-9._:-]+\z` (letters,
 * digits, and `._:-`) — an id carrying
 * a sentinel/control/whitespace byte would desync scanning and is rejected by
 * {@see mark()} with an \InvalidArgumentException. The combined
 * `prefix() . $id` is what gets validated.
 *
 * Zones discovered during the most recent {@see scan()} replace any
 * previously known zones for the same id (other ids persist). A recorded
 * zone stays queryable until a later scan replaces its id, {@see clear()}
 * drops it, or {@see close()} empties the registry — zones from an
 * off-screen frame are NOT forgotten on their own, so a consumer whose
 * layout reuses ids must re-scan every frame it renders (or clear) before
 * trusting {@see get()} / {@see anyInBounds()} results.
 */
final class Manager
{
    /** @var array<string, Zone> */
    private array $zones = [];
    private bool $enabled = true;
    private string $idPrefix = '';
    /** Class-level counter that gives every prefix-bearing manager a unique tag. */
    private static int $prefixCounter = 0;

    /**
     * Build a fresh un-prefixed manager, independent of every other
     * instance. Distinct from the {@see Zones} facade's shared default
     * manager ({@see Zones::defaultManager()}) — use that one only when
     * the whole program agrees on a single zone namespace.
     */
    public static function newGlobal(): self
    {
        return new self();
    }

    /**
     * Reset the auto-prefix counter. Test seam only — production code
     * must not rely on particular generated prefixes; they are unique
     * per process, not stable.
     */
    public static function resetPrefixCounterForTesting(): void
    {
        self::$prefixCounter = 0;
    }

    /**
     * Build a manager that namespaces every id with a unique prefix.
     *
     * Useful when you compose multiple SugarMouse-aware components into
     * the same Program — each component grabs its own prefixed manager
     * so two `ItemList`s using the literal id `"item-0"` don't collide.
     *
     * Mirrors bubblezone's `NewPrefix`. Pass an explicit `$prefix` to
     * fix the namespace; omit (or pass empty) to auto-generate one
     * from a monotonic counter. The generated prefix uses the
     * primitive's charset (`<n>-`), so `prefix() . $id` stays valid.
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
     * `mark()` returns `$content` verbatim (no markers wrapped — but the
     * id is STILL validated, so flipping the manager off must not become
     * a way to smuggle an illegal id through the emit path) and
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
     * Build the all-motion tracking byte sequence — RETURNS bytes, emits
     * nothing. The old `setMotionTracking` name taught setter reflexes and
     * a discarded call was a silent zero-effect failure; this name states
     * what the method is.
     *
     * When `$on` is true, returns the paired sequence enabling
     * all-motion tracking (mode 1003) AND SGR extended coordinate
     * reporting (mode 1006); when false, the LIFO disable pair.
     * ("SGR" names 1006 — the report byte format — not 1003.)
     * Bare 1003 would leave coordinates on legacy X10 single-byte
     * encoding, where everything past row/col 223 saturates — the
     * exact failure this package's own mode table warns about.
     *
     * The bytes delegate to sugar-core's canonical helpers
     * ({@see \SugarCraft\Core\Util\Ansi::mouseAllMotionOn()} /
     * {@see \SugarCraft\Core\Util\Ansi::mouseAllMotionOff()}), so
     * enable/disable ordering matches every other SugarCraft app.
     *
     * The caller writes the returned string to the terminal to
     * activate/deactivate motion reporting. This manager does not
     * directly emit to the TTY — it is a text-processing component.
     * This is unrelated to the zone markers. Whoever enables is also
     * responsible for disabling on every exit path — see the
     * lifecycle note in README's Mouse-tracking modes section.
     *
     * Mirrors bubblezone's `Manager::SetMotionTracking` (which emits
     * the bare 1003 pair; this lib ships the 1006 companion), named after
     * the house `Ansi::mouseAllMotion*` spelling it delegates to.
     */
    public function motionTrackingAnsi(bool $on): string
    {
        return $on === true ? Ansi::mouseAllMotionOn() : Ansi::mouseAllMotionOff();
    }

    /**
     * @deprecated Renamed to {@see motionTrackingAnsi()} — this is a pure
     *             byte-string builder, not a setter. The alias delegates
     *             byte-for-byte; it retires next cycle.
     */
    public function setMotionTracking(bool $on): string
    {
        return $this->motionTrackingAnsi($on);
    }

    /** Read-only accessor for the prefix this manager prepends to ids. */
    public function prefix(): string
    {
        return $this->idPrefix;
    }

    /**
     * Wrap $content with start/end markers for `prefix() . $id`.
     *
     * Delegates the marker encoding to {@see \SugarCraft\Mouse\Mark}
     * (validation inside {@see \SugarCraft\Mouse\Mark::wrap()}) so the
     * wire format lives in exactly one place. When the manager is
     * disabled the content is returned verbatim — but the id is still
     * validated, so disabling the manager cannot smuggle an illegal id
     * through the emit path.
     *
     * @throws \InvalidArgumentException When `prefix() . $id` contains a byte
     *         outside the primitive's `\A[A-Za-z0-9._:-]+\z` charset (validated by
     *         Mark::wrap). This package's own prefixes and real consumer ids
     *         (`tab-N`, `cell:N`, `item:N`) already comply.
     */
    public function mark(string $id, string $content): string
    {
        // Mark::wrap validates unconditionally and emits only when enabled —
        // routing the disabled case through it as well keeps the validation
        // contract identical in both modes.
        return (new Mark($this->enabled))->wrap($this->idPrefix . $id, $content);
    }

    /**
     * Strip markers from $rendered, recording each zone's bounding box,
     * and return the cleaned frame ready for the terminal.
     *
     * Bounding-box computation is delegated to {@see \SugarCraft\Mouse\Scan}
     * (CJK-width aware via sugar-core's Width), then the discovered zones are
     * upserted into this manager's registry as {@see Zone} adapters — same id,
     * same bounds. The registry merges rather than replaces: ids seen this
     * scan overwrite prior entries; other ids persist. Stripping goes
     * through {@see \SugarCraft\Mouse\Scan::strip()}, the same rule parse
     * decodes, so bounds and bytes stay in lock-step.
     *
     * A prefixed manager only absorbs tags whose id carries its own prefix —
     * markers from a sibling component's namespace in the same frame are
     * stripped from the returned bytes but never enter this registry.
     *
     * No-op when {@see setEnabled()} has flipped the manager off — the input
     * passes through unchanged and no zones are recorded.
     *
     * @param int|null $width Viewport width; when given, zone columns are
     *                        clamped into [1, $width] (see
     *                        {@see \SugarCraft\Mouse\Scan::parse()}).
     */
    public function scan(string $rendered, ?int $width = null): string
    {
        if (!$this->enabled) {
            return $rendered;
        }

        // The primitive layer (Scan) is the single source of truth for parsing + bounds.
        foreach ((new Scan())->parse($rendered, $width) as $id => $mouseZone) {
            if ($this->idPrefix !== '' && !str_starts_with($id, $this->idPrefix)) {
                continue; // foreign namespace in the same frame — strip it, don't own it
            }
            $this->zones[$id] = Zone::fromMouseZone($mouseZone);
        }

        return Scan::strip($rendered);
    }

    /**
     * Look up a recorded zone.
     *
     * Accepts the SHORT id passed to {@see mark()} (this manager's prefix is
     * tried first) as well as the WIRE id stored in a hit result
     * (`$zone->id`, already prefixed) — the trackers hand back wire ids, so
     * both spellings must round-trip on a prefixed manager.
     */
    public function get(string $id): ?Zone
    {
        return $this->zones[$this->idPrefix . $id] ?? $this->zones[$id] ?? null;
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
     * `Manager::Clear(id string)` overload. Like {@see get()}, the `$id`
     * may be the short or the wire spelling.
     */
    public function clear(?string $id = null): void
    {
        if ($id === null) {
            $this->zones = [];
            return;
        }
        unset($this->zones[$this->idPrefix . $id], $this->zones[$id]);
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
     * on equal area the first-recorded zone wins. That tie rule is the only
     * part the primitive {@see \SugarCraft\Mouse\Scanner} `hit()` shares:
     * the Scanner resolves purely by earliest registration, so the two
     * layers can disagree about who owns one painted frame. This registry
     * has no stacking concept — no priority, layer, or modal state — see the
     * overlay contract in README's Zones section before scanning a
     * full-screen modal over a live registry. Returns null if no zone
     * matches (or if the Msg isn't a {@see MouseMsg} to begin with — handy
     * when models blanket-route every Msg through this helper).
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
            if ($zone->inBounds($mouse) === true) {
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
     * Every zone whose bounds contain `$mouse`, ordered innermost first
     * (ascending area; equal areas keep registration order, matching
     * {@see anyInBounds()}). Lets a consumer route a click to ALL owners
     * of a cell — e.g. a container and the button inside it — instead of
     * only the smallest.
     *
     * @return list<Zone>
     */
    public function allInBounds(Msg $mouse): array
    {
        if (!$mouse instanceof MouseMsg) {
            return [];
        }
        $hits = [];
        foreach ($this->zones as $zone) {
            if ($zone->inBounds($mouse) === true) {
                $hits[] = $zone;
            }
        }
        usort($hits, static fn (Zone $a, Zone $b): int
            => ($a->width() * $a->height()) <=> ($b->width() * $b->height()));
        return $hits;
    }

    /**
     * Pure-geometry lookup without a Msg: the innermost zone covering cell
     * ($col, $row), 1-based — same area/tie rule as {@see anyInBounds()}.
     * Lets consumers resolve what is under the wheel, the caret, or an
     * arbitrary position without fabricating a MouseMsg.
     */
    public function zoneAt(int $col, int $row): ?Zone
    {
        $hit = null;
        $smallestArea = PHP_INT_MAX;
        foreach ($this->zones as $zone) {
            if ($zone->startCol <= $col && $col <= $zone->endCol
                && $zone->startRow <= $row && $row <= $zone->endRow
            ) {
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
        // The instanceof is TYPE NARROWING, not a behavior guard:
        // anyInBounds() already returns null for non-MouseMsg traffic, so a
        // non-null $zone proves the type — restated here so static analysis
        // accepts the MsgZoneInBounds construction.
        if ($zone !== null && $mouse instanceof MouseMsg) {
            return $model->update(new MsgZoneInBounds($zone, $mouse));
        }
        return $model->update($mouse);
    }
}
