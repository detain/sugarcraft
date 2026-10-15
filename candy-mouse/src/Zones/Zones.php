<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;

/**
 * Package-level facade over a single shared {@see Manager} instance.
 *
 * Mirrors upstream bubblezone's package-level surface:
 * `bubblezone.DefaultManager` (the singleton) plus the free functions
 * `NewPrefix`, `Mark`, `Clear`, `Get`, `Scan`, `Close`, `SetEnabled`,
 * `Enabled`. PHP doesn't have free functions in arbitrary namespaces
 * (well, it does — but they aren't autoloadable without a tiny
 * `files: []` shim), so we expose them as static methods on this
 * class. The semantics are identical: every call routes through the
 * shared manager returned by {@see defaultManager()}.
 *
 * ```php
 * use SugarCraft\Zone\Zones;
 *
 * // mark / scan with the package-level default manager.
 * $rendered = Zones::mark('header', $header)
 *           . "\n"
 *           . Zones::mark('footer', $footer);
 * $cleaned  = Zones::scan($rendered);
 * if (Zones::get('header')?->inBounds($mouse)) { … }
 * ```
 *
 * Prefer an explicit `Manager::newGlobal()` per-program when you want
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
        return self::$default ??= Manager::newGlobal();
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

    /** Mirrors `Scan`. */
    public static function scan(string $rendered): string
    {
        return self::defaultManager()->scan($rendered);
    }

    /** Mirrors `Get`. */
    public static function get(string $id): ?Zone
    {
        return self::defaultManager()->get($id);
    }

    /**
     * Mirrors `Clear`. Pass an id to drop a single zone; omit to wipe
     * every zone tracked by the default manager.
     */
    public static function clear(?string $id = null): void
    {
        self::defaultManager()->clear($id);
    }

    /**
     * Mirrors `Close`. After calling this the default manager is
     * disabled (mark / scan become pass-through). Idempotent.
     */
    public static function close(): void
    {
        self::defaultManager()->close();
    }

    /** Mirrors `SetEnabled`. */
    public static function setEnabled(bool $on): void
    {
        self::defaultManager()->setEnabled($on);
    }

    /** Mirrors `Enabled`. */
    public static function isEnabled(): bool
    {
        return self::defaultManager()->isEnabled();
    }

    /**
     * Mirrors `NewPrefix` — but unlike upstream (which returns a
     * string prefix scoped to the default manager), this returns a
     * **fresh prefixed Manager**. Compose multiple components by
     * giving each its own prefixed manager rather than sharing the
     * default.
     */
    public static function newPrefix(string $prefix = ''): Manager
    {
        return Manager::newPrefix($prefix);
    }

    /** Mirrors `AnyInBounds`. */
    public static function anyInBounds(Msg $mouse): ?Zone
    {
        return self::defaultManager()->anyInBounds($mouse);
    }

    /** Mirrors `AnyInBoundsAndUpdate`. */
    public static function anyInBoundsAndUpdate(Model $model, Msg $mouse): array
    {
        return self::defaultManager()->anyInBoundsAndUpdate($model, $mouse);
    }
}
