<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

/**
 * Package-level facade over a single shared {@see Manager} instance.
 *
 * Mirrors upstream bubblezone's package-level surface:
 * `bubblezone.DefaultManager` (the singleton) plus the free functions
 * `NewPrefix`, `Mark`, `Clear`, `Get`, `Scan`, `Close`, `SetEnabled`,
 * `Enabled`. PHP has no autoloadable free functions in arbitrary
 * namespaces, so the surface ships as static methods on this class.
 * The semantics are identical: every call routes through the shared
 * manager returned by {@see defaultManager()}.
 *
 * ```php
 * use SugarCraft\Mouse\Zones\Zones;
 *
 * // mark / scan with the package-level default manager.
 * $rendered = Zones::mark('header', $header)
 *           . "\n"
 *           . Zones::mark('footer', $footer);
 * $cleaned  = Zones::scan($rendered);
 * if (Zones::get('header')?->inBounds($mouse)) { … }
 * ```
 *
 * Prefer an explicit `Manager::newUnprefixed()` per-program when you want
 * isolation between sub-trees (e.g. tests, library composition).
 * The default manager is convenient for app code that has only one
 * UI tree.
 */
final class Zones
{
    private static ?Manager $default = null;

    private function __construct() {}

    /**
     * The shared default {@see Manager}. Lazily constructed on first
     * access; survives between calls so zone state persists across
     * `mark() → scan() → get()` cycles.
     */
    public static function defaultManager(): Manager
    {
        return self::$default ??= Manager::newUnprefixed();
    }

    /**
     * Replace the shared default manager. Mostly useful in tests
     * (`tearDown(): Zones::setDefaultManager(null)` flushes state)
     * or when you want every package-level call to route through a
     * pre-configured prefixed manager.
     */
    public static function setDefaultManager(?Manager $m): void
    {
        self::$default = $m;
    }

    /** Mirrors bubblezone's package-level `Mark`. */
    public static function mark(string $id, string $content): string
    {
        return self::defaultManager()->mark($id, $content);
    }

    /** Mirrors bubblezone's `Scan`. `$width` clamps zone columns into [1, $width]. */
    public static function scan(string $rendered, ?int $width = null): string
    {
        return self::defaultManager()->scan($rendered, $width);
    }

    /** Mirrors bubblezone's `Get`. */
    public static function get(string $id): ?Zone
    {
        return self::defaultManager()->get($id);
    }

    /**
     * Mirrors bubblezone's `Clear`. Pass an id to drop a single zone; omit to wipe
     * every zone tracked by the default manager.
     */
    public static function clear(?string $id = null): void
    {
        self::defaultManager()->clear($id);
    }

    /**
     * Mirrors bubblezone's `Close`. After calling this the default manager
     * is disabled (mark / scan become pass-through) AND its registry is
     * dropped — Idempotent. WARNING: subsequent package-level calls
     * (`Zones::define` / `Zones::scan` / `Zones::get` …) then pass through
     * silently and hit-tests resolve nothing, i.e. a whole dead UI with no
     * error. Re-arm with `Zones::setDefaultManager(null)`, which lazily
     * builds a fresh enabled default on the next access.
     */
    public static function close(): void
    {
        self::defaultManager()->close();
    }

    /** Mirrors bubblezone's `SetEnabled`. */
    public static function setEnabled(bool $on): void
    {
        self::defaultManager()->setEnabled($on);
    }

    /** Mirrors bubblezone's `Enabled`. */
    public static function isEnabled(): bool
    {
        return self::defaultManager()->isEnabled();
    }

    /**
     * Mirrors bubblezone's `NewPrefix` — but unlike upstream (which returns a
     * string prefix scoped to the default manager), this returns a
     * **fresh prefixed Manager**. Compose multiple components by
     * giving each its own prefixed manager rather than sharing the
     * default.
     */
    public static function newPrefix(string $prefix = ''): Manager
    {
        return Manager::newPrefix($prefix);
    }

    /** Mirrors bubblezone's `AnyInBounds`. */
    public static function anyInBounds(Msg $mouse): ?Zone
    {
        return self::defaultManager()->anyInBounds($mouse);
    }

    /** Mirrors bubblezone's `AnyInBoundsAndUpdate`. */
    public static function anyInBoundsAndUpdate(Model $model, Msg $mouse): array
    {
        return self::defaultManager()->anyInBoundsAndUpdate($model, $mouse);
    }
}
