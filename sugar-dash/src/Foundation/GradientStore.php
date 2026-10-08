<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Gradient101;

/**
 * Named 101-entry color ramps (index = percent), the registry a themed
 * system monitor reads on every meter / graph cell — `at('cpu', 73)` is
 * btop's `Theme::g("cpu")[73]`.
 *
 * Ramps follow btop's start / optional mid / optional end law: start+end is
 * one 0..100 leg, start+mid+end is two 50-wide legs meeting exactly at mid
 * (index 50), and start alone fills all 101 entries with start. Expansion
 * is delegated to {@see Gradient101} so the truncating integer math lives in
 * one place.
 *
 * Immutable: {@see withGradient()} returns a new store. Expanded ramps are
 * memoized process-wide by stop colors (bounded by {@see MEMO_CAP}, oldest
 * evicted first), so re-deriving the same gradient (theme reload, two stores
 * sharing a palette) never re-expands. A store keeps its own ramps, so
 * eviction never changes what an existing store returns.
 *
 * Mirrors aristocratos/btop Theme::generateGradients / Theme::g
 * (src/btop_theme.cpp, src/btop_theme.hpp).
 */
final class GradientStore
{
    /**
     * Upper bound on memoized ramps. A btop theme defines ~15 gradients, so
     * this holds many theme reloads while keeping a long-lived process that
     * cycles generated palettes from growing without limit.
     */
    public const MEMO_CAP = 256;

    /** @var array<string, list<Color>> stop-key → expanded ramp, insertion-ordered for FIFO eviction */
    private static array $memo = [];

    /**
     * @param array<string, list<Color>> $ramps
     */
    private function __construct(
        private readonly array $ramps,
    ) {}

    public static function new(): self
    {
        return new self([]);
    }

    /**
     * Build from btop-style flat keys: every `<name>_start` entry yields a
     * gradient `<name>` using `<name>_mid` / `<name>_end` when present. Keys
     * without the `_start` suffix are ignored, as in generateGradients.
     *
     * @param array<string, Color|null> $colors null = unset (btop's -1 rgb)
     */
    public static function fromStops(array $colors): self
    {
        $store = self::new();
        foreach ($colors as $key => $start) {
            if (!is_string($key) || !str_ends_with($key, '_start') || !$start instanceof Color) {
                continue;
            }
            $name = substr($key, 0, -strlen('_start'));
            $store = $store->withGradient(
                $name,
                $start,
                $colors[$name . '_mid'] ?? null,
                $colors[$name . '_end'] ?? null,
            );
        }
        return $store;
    }

    /**
     * Expand start / mid / end into btop's 101-entry ramp (memoized).
     *
     * A mid without an end is ignored (btop only iterates when end is set,
     * otherwise it fills with start).
     *
     * @return list<Color> exactly 101 entries
     */
    public static function ramp(Color $start, ?Color $mid = null, ?Color $end = null): array
    {
        $key = self::key($start) . '|' . ($mid === null ? '-' : self::key($mid)) . '|'
            . ($end === null ? '-' : self::key($end));
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        if ($end === null) {
            $ramp = array_fill(0, 101, $start);
        } else {
            $ramp = Gradient101::expand($mid === null ? [$start, $end] : [$start, $mid, $end]);
        }
        if (count(self::$memo) >= self::MEMO_CAP) {
            unset(self::$memo[array_key_first(self::$memo)]);
        }
        return self::$memo[$key] = $ramp;
    }

    /**
     * Register (or replace) gradient `$name`. This is also how a consumer
     * injects btop's pseudo-gradients, e.g. `proc` = main_fg → inactive_fg
     * and `proc_color` = inactive_fg → process_start.
     */
    public function withGradient(string $name, Color $start, ?Color $mid = null, ?Color $end = null): self
    {
        $ramps = $this->ramps;
        $ramps[$name] = self::ramp($start, $mid, $end);
        return new self($ramps);
    }

    /**
     * Color at `$percent` of gradient `$name`; percent is clamped to 0..100.
     *
     * @throws \OutOfBoundsException when no gradient `$name` exists (btop's
     *         `gradients.at()` throws likewise)
     */
    public function at(string $name, int $percent): Color
    {
        return $this->gradient($name)[max(0, min(100, $percent))];
    }

    /**
     * The full 101-entry ramp for `$name`.
     *
     * @return list<Color>
     * @throws \OutOfBoundsException when no gradient `$name` exists
     */
    public function gradient(string $name): array
    {
        if (!isset($this->ramps[$name])) {
            throw new \OutOfBoundsException(sprintf('Unknown gradient "%s"', $name));
        }
        return $this->ramps[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->ramps[$name]);
    }

    /** @return list<string> registered gradient names, in registration order */
    public function names(): array
    {
        return array_map('strval', array_keys($this->ramps));
    }

    private static function key(Color $c): string
    {
        // ansiIndex is part of identity: a start-only ramp hands back the
        // stop instance itself, so an indexed color must not alias its rgb twin.
        return $c->r . ',' . $c->g . ',' . $c->b . '#' . ($c->ansiIndex ?? '');
    }
}
