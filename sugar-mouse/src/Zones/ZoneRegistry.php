<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

/**
 * The per-application home of the state the Zones layer used to keep in
 * statics: the default {@see Manager} and the counter behind auto-generated
 * {@see Manager::newPrefix()} prefixes.
 *
 * Inject one registry per program (or per test) and build managers from it;
 * two registries share nothing, so composing two programs in one process — or
 * two tests in one run — can no longer leak zones or prefix numbers between
 * them. The package-level {@see Zones} facade and the static
 * {@see Manager::newPrefix()} keep working for bubblezone parity: both route
 * through ONE process registry ({@see Zones::registry()}), which is the only
 * static slot left in the layer.
 *
 * ```php
 * $zones = ZoneRegistry::new();
 * $list  = $zones->newPrefix();         // "1-"
 * $menu  = $zones->newPrefix();         // "2-"
 * $frame = $zones->defaultManager()->scan($rendered);
 * ```
 *
 * Mutable by design, like the {@see Manager}s it hands out: it is a registry,
 * not a value.
 */
final class ZoneRegistry
{
    private ?Manager $default = null;

    /** Highest auto or reserved numeric prefix handed out or claimed so far. */
    private int $prefixCounter = 0;

    public static function new(): self
    {
        return new self();
    }

    /**
     * This registry's shared default manager (un-prefixed), built lazily on
     * first access and kept so zone state persists across `mark() → scan() →
     * get()` cycles.
     */
    public function defaultManager(): Manager
    {
        return $this->default ??= Manager::newUnprefixed();
    }

    /**
     * Replace the default manager; null drops it so the next
     * {@see defaultManager()} builds a fresh enabled one.
     */
    public function setDefaultManager(?Manager $manager): void
    {
        $this->default = $manager;
    }

    /**
     * A manager that namespaces every id with a prefix unique within THIS
     * registry. Same contract as {@see Manager::newPrefix()}: an empty
     * $prefix auto-generates `<n>-`; an explicit numeric `<n>-` prefix is
     * reserved, so a later auto prefix never merges into it.
     */
    public function newPrefix(string $prefix = ''): Manager
    {
        if ($prefix === '') {
            return Manager::newWithIdPrefix((string) (++$this->prefixCounter) . '-');
        }
        if (preg_match('/^(\d+)-$/', $prefix, $reserved) === 1) {
            $this->prefixCounter = max($this->prefixCounter, (int) $reserved[1]);
        }
        return Manager::newWithIdPrefix($prefix);
    }

    /**
     * A new registry with no default manager whose auto prefixes continue
     * after this one's. {@see Zones::setRegistry()} resets through it, so a
     * manager built before the reset never shares a prefix with one built
     * after it: {@see Manager::scan()} accepts any id that starts with its
     * prefix, and two managers holding `1-` would accept each other's zones.
     */
    public function successor(): self
    {
        $next = new self();
        $next->prefixCounter = $this->prefixCounter;
        return $next;
    }

    /** A fresh un-prefixed manager, independent of the default one. */
    public function newUnprefixed(): Manager
    {
        return Manager::newUnprefixed();
    }
}
