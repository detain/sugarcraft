<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * Turn what a user typed — an id, a name or an id prefix — into the stored
 * session rows it can mean. The one resolver `--resume <target>` and the
 * `sugarcrush session show|rename|delete|pin|archive <target>` verbs share
 * (Appendix P §3.4), so a target that opens a session also renames it.
 *
 * Precedence: an exact id, then an exact name (the most recently used row
 * carrying it), then every row whose id STARTS WITH the target. Only the
 * prefix step can answer with more than one row; the caller decides what an
 * ambiguous answer means (`--resume` treats it as no match, the CLI verbs list
 * the candidates and exit 2).
 *
 * EVERY KIND, ARCHIVED OR NOT. The default list ({@see SessionQuery::new()})
 * hides sub-agent and background children and archived rows, and the prefix
 * step used to search that list — so a prefix of an archived session's id
 * matched nothing while its full id worked, and `session unarchive <prefix>`
 * could never have found the row it exists to bring back. Naming a row is an
 * explicit act; the default filter is for browsing.
 *
 * There is no charmbracelet/crush equivalent; opencode resolves its
 * `--session <id>` by exact id only.
 */
final class SessionResolver
{
    /**
     * @return list<SessionRow> no rows when nothing answers; more than one
     *   only for an ambiguous id prefix
     */
    public static function matches(SessionStore|EnhancedSessionStore $store, string $target): array
    {
        if ($target === '') {
            return [];
        }

        $row = $store->getSession($target) ?? $store->getSessionByName($target);
        if (\is_array($row)) {
            return [SessionRow::fromArray($row)];
        }

        // The LIKE prefilter keeps the scan to rows mentioning the target at
        // all; the prefix test below is the real rule.
        $query = SessionQuery::new()
            ->withKinds()
            ->withIncludeArchived()
            ->withSearch($target)
            ->withLimit(\PHP_INT_MAX);

        return \array_values(\array_filter(
            $store->listSessionsFiltered($query),
            static fn(SessionRow $r): bool => \str_starts_with($r->id, $target),
        ));
    }

    /**
     * The one row $target names, or null when nothing — or more than one
     * prefix match — answers.
     */
    public static function find(SessionStore|EnhancedSessionStore $store, string $target): ?SessionRow
    {
        $matches = self::matches($store, $target);

        return \count($matches) === 1 ? $matches[0] : null;
    }
}
