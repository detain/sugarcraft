<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * What a `sessions` row is — the `kind` column.
 *
 * Only `Main` and `Branch` rows are the user's own conversations; they are
 * what the tab strip, the default `/sessions` list and `--continue` show.
 * `Subagent` rows are Task children recorded under the delegating session
 * and `Background` rows are `/bg` / `/fork` daemons. Both stay reachable
 * through their parent ({@see EnhancedSessionStore::childrenOf()}) or an
 * explicit {@see SessionQuery}, and are kept out of the default list so a
 * busy agent run cannot flood it (Appendix P §3.1, risk 8).
 *
 * This is a SugarCraft session type, not a port — charmbracelet/crush keeps
 * no session kinds.
 */
enum SessionKind: string
{
    case Main = 'main';
    case Branch = 'branch';
    case Subagent = 'subagent';
    case Background = 'background';

    /**
     * The kinds the default list, the tab strip and `--continue` show.
     *
     * @return list<self>
     */
    public static function visibleByDefault(): array
    {
        return [self::Main, self::Branch];
    }

    /**
     * The stored value read back leniently: a row written by a newer build
     * with a kind this one does not know is treated as `Main` rather than
     * failing the whole list.
     */
    public static function fromStored(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::Main) : self::Main;
    }
}
