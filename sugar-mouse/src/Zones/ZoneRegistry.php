<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

/**
 * The per-application home of the state the Zones layer used to keep in
 * statics: the default {@see Manager} and the counter behind auto-generated
 * {@see Manager::newPrefix()} prefixes.
 *
 * Inject one registry per program (or per test) and build managers from it:
 * each registry owns its own default manager, so composing two programs in
 * one process — or two tests in one run — can no longer leak zones between
 * them. The package-level {@see Zones} facade and the static
 * {@see Manager::newPrefix()} keep working for bubblezone parity: both route
 * through ONE process registry ({@see Zones::registry()}).
 *
 * Prefix scope. {@see Manager::scan()} accepts any id that starts with its
 * prefix, so two managers holding the same auto prefix absorb each other's
 * zones. Every registry built by {@see new()} — the process registry among
 * them — therefore draws auto prefixes from ONE process-wide counter, the
 * same guarantee the old static {@see Manager::newPrefix()} gave: no two
 * default registries ever hand out the same `<n>-`. A registry built by
 * {@see isolated()} keeps its own counter instead, starting at `1-`; its
 * prefixes are unique only within that registry, so its managers must not
 * scan frames painted by another registry's managers.
 *
 * ```php
 * $zones = ZoneRegistry::new();
 * $list  = $zones->newPrefix();         // "<n>-", unique in the process
 * $menu  = $zones->newPrefix();         // "<n+1>-"
 * $frame = $zones->defaultManager()->scan($rendered);
 *
 * $test  = ZoneRegistry::isolated();
 * $test->newPrefix();                   // "1-", unique in $test only
 * ```
 *
 * Mutable by design, like the {@see Manager}s it hands out: it is a registry,
 * not a value.
 */
final class ZoneRegistry
{
    /**
     * The one process-wide auto prefix counter every {@see new()} registry
     * shares: the highest auto or reserved numeric prefix handed out or
     * claimed by any of them so far.
     */
    private static int $processPrefixCounter = 0;

    private ?Manager $default = null;

    /** The {@see isolated()} registry's own counter; unused when shared. */
    private int $ownPrefixCounter = 0;

    private function __construct(private readonly bool $sharesProcessCounter)
    {
    }

    /** A registry whose auto prefixes come from the one process-wide counter. */
    public static function new(): self
    {
        return new self(true);
    }

    /**
     * A registry with its own auto prefix counter, starting at `1-`. Its
     * prefixes are unique within this registry only: a manager it hands out
     * may share a prefix with one from any other registry.
     */
    public static function isolated(): self
    {
        return new self(false);
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
     * A manager that namespaces every id with a prefix unique within this
     * registry's counter scope (the process for {@see new()}, the registry
     * for {@see isolated()}). Same contract as {@see Manager::newPrefix()}:
     * an empty $prefix auto-generates `<n>-`; an explicit numeric `<n>-`
     * prefix is reserved, so a later auto prefix never merges into it.
     */
    public function newPrefix(string $prefix = ''): Manager
    {
        if ($prefix === '') {
            return Manager::newWithIdPrefix((string) $this->claim(null) . '-');
        }
        // A run of more than 18 digits is past any value the auto counter can
        // reach, so it needs no reservation; claiming it would push the
        // counter to PHP_INT_MAX (the (int) cast saturates) and make the next
        // auto increment overflow.
        if (preg_match('/^(\d{1,18})-$/', $prefix, $reserved) === 1) {
            $this->claim((int) $reserved[1]);
        }
        return Manager::newWithIdPrefix($prefix);
    }

    /**
     * A new registry with no default manager whose auto prefixes continue
     * after this one's. {@see Zones::setRegistry()} resets through it, so a
     * manager built before the reset never shares a prefix with one built
     * after it: {@see Manager::scan()} accepts any id that starts with its
     * prefix, and two managers holding `1-` would accept each other's zones.
     * A {@see new()} registry's successor shares the process counter; an
     * {@see isolated()} one's starts from a copy of its counter.
     */
    public function successor(): self
    {
        $next = new self($this->sharesProcessCounter);
        $next->ownPrefixCounter = $this->ownPrefixCounter;
        return $next;
    }

    /** A fresh un-prefixed manager, independent of the default one. */
    public function newUnprefixed(): Manager
    {
        return Manager::newUnprefixed();
    }

    /**
     * Advance the counter in this registry's scope: null takes the next auto
     * number, an int reserves that number. Returns the counter afterwards.
     */
    private function claim(?int $reserve): int
    {
        if ($this->sharesProcessCounter) {
            return self::$processPrefixCounter = $reserve === null
                ? self::$processPrefixCounter + 1
                : max(self::$processPrefixCounter, $reserve);
        }
        return $this->ownPrefixCounter = $reserve === null
            ? $this->ownPrefixCounter + 1
            : max($this->ownPrefixCounter, $reserve);
    }
}
