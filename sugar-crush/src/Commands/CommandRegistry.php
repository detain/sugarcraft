<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\CharFold;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * The ONE list of commands both surfaces read: the "/" popup ({@see
 * \SugarCraft\Crush\Renderer::renderSlashMenu()}) via {@see filter()}, and
 * the Ctrl+P palette via {@see PaletteAction::all()}, whose item list is
 * derived from the rows here rather than from the enum's own cases. Before
 * this, the two surfaces kept independent lists and drifted - a command
 * added to one was silently missing from the other.
 *
 * The rows come from one spec file per command under `src/Commands/Specs/`
 * ({@see BuiltInCommands}), and the same file names the private `Chat` handler
 * the row dispatches to, so a row and its dispatch can no longer be added
 * apart: `Chat::dispatchCommand()` routes through the spec table rather than
 * through a `match` of its own. A row with no handler is palette-only and must
 * be `slashVisible: false`; `Commands\SlashDispatchTest`'s
 * `testEverySlashVisibleRegistryRowHasALiveDispatchHandler()` still submits
 * `/name` for every visible row through the real `Chat::update()` and fails
 * when the turn goes to the MODEL instead of to a handler.
 *
 * The docs that list these rows — `docs/COMMANDS.md`'s built-in table and
 * README's slash roster — are generated from them by
 * `php tools/gen-command-docs.php --write`.
 */
final class CommandRegistry
{
    /**
     * The CONTROL-PLANE command names, which a repository-supplied `*.md` file
     * may NOT take over. {@see CommandLoader::loadAll()} enforces it.
     *
     * Every other built-in is overridable on purpose — that is the feature the
     * tiering exists for, and a project that wants its own `/compact` or
     * `/review` gets it. These nine are different in kind: they are how the
     * user drives, inspects, pays for and LEAVES the application, so a clone
     * that redefined one would be answering a keystroke the user aimed at the
     * app rather than at the model. `/exit` was measured doing exactly that —
     * an `exit.md` in a checkout turned the quit key into a prompt while idle,
     * and left it quitting mid-turn (the mid-turn arm in
     * {@see \SugarCraft\Crush\Chat::submit()} bypasses expansion), i.e. an
     * override whose effect depended on whether a reply was streaming.
     *
     * WHY A LIST AND NOT A FLAG ON {@see CommandSpec}: the property would live
     * on the registry rows, and the check has to run against the FILE-BASED row
     * that is trying to replace one — at which point the built-in it shadows is
     * already gone from the merged map. The names are the thing being reserved,
     * so the names are what is written down.
     *
     * `permissions` was reserved here for two rounds while {@see all()} listed
     * NO row for it and {@see \SugarCraft\Crush\Chat::dispatchCommand()} had
     * no arm — a name held against a project's `permissions.md` on behalf of a
     * command that did not exist, so the only thing the reservation actually
     * did was refuse the override. It now has both, and this paragraph is kept
     * rather than deleted because the shape it describes is the one to watch
     * for: a reserved name is a promise, and the only way to tell whether the
     * promise was kept is `Commands\SlashDispatchTest`, which drives every
     * VISIBLE row through the real dispatch. `quit` and `config` are the
     * remaining asymmetry and are deliberate — neither has a row of its own
     * because they are aliases of `exit` and `settings`, but both ARE
     * dispatched, so they are reserved names that work rather than ones that
     * do not.
     */
    public const CONTROL_PLANE = ['budget', 'clear', 'config', 'exit', 'help', 'model', 'permissions', 'quit', 'settings'];

    /** Whether $name is one of {@see CONTROL_PLANE}. */
    public static function isControlPlane(string $name): bool
    {
        return \in_array($name, self::CONTROL_PLANE, true);
    }

    /**
     * Every command known to either surface, in display order: the rows of the
     * spec files under `src/Commands/Specs/`, in file order (DH-CMDS). A command
     * is added by adding its spec file, not by editing this method — see
     * {@see BuiltInCommands} for the file shape and why it is one file per row.
     *
     * @return list<CommandSpec>
     */
    public static function all(): array
    {
        return array_map(
            static fn(BuiltInCommand $command): CommandSpec => $command->spec,
            BuiltInCommands::all(),
        );
    }

    /**
     * The rows the "/" popup may list - everything except palette-only
     * pseudo-commands.
     *
     * @return list<CommandSpec>
     */
    public static function slashCommands(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CommandSpec $spec): bool => $spec->slashVisible,
        ));
    }

    /**
     * The rows the Ctrl+P palette lists, in declared order.
     *
     * @return list<CommandSpec>
     */
    public static function paletteEntries(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CommandSpec $spec): bool => $spec->paletteAction !== null,
        ));
    }

    /**
     * The row that owns $action, or null when the action has no row (a bug -
     * {@see PaletteAction::spec()} turns it into an exception).
     */
    public static function forPaletteAction(PaletteAction $action): ?CommandSpec
    {
        foreach (self::all() as $spec) {
            if ($spec->paletteAction === $action) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * Slash commands matching the in-progress "/name" the user is typing,
     * fuzzy-ranked by the same matcher the Ctrl+P palette uses, so "/rwd"
     * surfaces "/rewind" instead of nothing. An empty prefix (bare "/")
     * returns every slash command in declared order.
     *
     * DERIVED from {@see filterMatchResults()} rather than filtering in
     * parallel with it, for the reason {@see \SugarCraft\Crush\Chat::paletteMatches()}
     * is derived from `paletteMatchResults()`: two filters over the same rows
     * are two rows-and-indices lists that can fall out of step, and the "/"
     * popup pairs a spec with its matched-character indices on every row it
     * paints.
     *
     * $rows OVERRIDES the registry's own list, and null is not merely a default
     * spelling of it: a caller that has file-based commands
     * ({@see CommandLoader::loadAll()}) must filter over the MERGED set, or a
     * custom command would be dispatchable by typing its full name yet invisible
     * in the popup that is supposed to teach the name. The rows are a parameter
     * rather than something this class discovers because discovery needs a
     * project root and a `$HOME` — state a static registry has no business
     * holding, and which {@see \SugarCraft\Crush\Chat} already carries.
     *
     * @param list<CommandSpec>|null $rows the rows to match against; null uses
     *        {@see slashCommands()}. Callers pass ALREADY slash-visible rows —
     *        this method does not re-filter on `slashVisible`, so a palette-only
     *        row handed in here would be listed.
     * @return list<CommandSpec>
     */
    public static function filter(string $prefix, ?array $rows = null): array
    {
        // Keyed on NAME, which is only equivalent to the row list while names
        // are unique: two rows sharing one would return the later spec twice
        // while filterMatchResults() kept both rows, and the row-for-row test
        // could not see it (both sides of that comparison are names). Pinned by
        // `CommandRegistryTest::testCommandNamesAreUniqueBecauseFilterKeysOnThem()`.
        $rows ??= self::slashCommands();

        $byName = [];
        foreach ($rows as $spec) {
            $byName[$spec->name] = $spec;
        }

        return array_values(array_map(
            static fn(MatchResult $result): CommandSpec => $byName[$result->haystack],
            self::filterMatchResults($prefix, $rows),
        ));
    }

    /**
     * {@see filter()}'s rows with their matched-character indices kept, in the
     * SAME order - the spec list is just this list's haystacks looked up by
     * name (crush_code.md Phase 4 item 5: the indices used to be discarded
     * here, so {@see \SugarCraft\Crush\Renderer::renderSlashMenu()} had
     * nothing to highlight with while the Ctrl+P palette beside it did).
     *
     * An empty prefix yields index-less results, exactly as the palette's
     * empty query does - {@see \SugarCraft\Fuzzy\Highlighter} no-ops on
     * those, so bare "/" paints unstyled names rather than fully-highlighted
     * ones.
     *
     * @param list<CommandSpec>|null $rows see {@see filter()}; the two must be
     *        given the SAME list or the row-for-row pairing the popup relies on
     *        breaks, which is why {@see filter()} forwards its own.
     * @return list<MatchResult>
     */
    public static function filterMatchResults(string $prefix, ?array $rows = null): array
    {
        $commands = $rows ?? self::slashCommands();
        if ($prefix === '') {
            return array_map(
                static fn(CommandSpec $spec): MatchResult => new MatchResult('', $spec->name, 0, []),
                $commands,
            );
        }

        $names = array_map(static fn(CommandSpec $spec): string => $spec->name, $commands);

        return self::fuzzyMatches($prefix, $names);
    }

    /**
     * The anchored survivors of one full-query ranking, in the matcher's order.
     *
     * The matcher runs in requireFullQuery mode, so every result already
     * carries one matched index per query character - the LOCAL-alignment
     * partial hit (query "re" scoring against "agents" on the "e" alone) never
     * reaches here. What is left to check is the anchor: the first query
     * character must land on the command's first character. A slash command
     * is typed from its start, so anchoring is what makes the popup narrow as
     * the user types instead of widening.
     *
     * The anchor is a property of the NAME, not of the one alignment the
     * matcher happened to report: "/ab" against "a__ab" reports the adjacent
     * "ab" run at [3, 4] because it outscores the spread-out [0, 4], yet the
     * name plainly starts with "a" and carries a "b" after it. Reading only
     * the reported alignment dropped such a name, so a result whose best
     * covering alignment is unanchored is re-placed by {@see anchoredPlacement()}
     * and kept when ANY covering alignment starts at index 0.
     *
     * Ranking is untouched: the result keeps the matcher's score for the name
     * and its slot in the matcher's order (score desc, then name); only its
     * indices change, to the anchored placement the popup admits it by and
     * highlights.
     *
     * @param list<MatchResult> $ranked
     * @return list<MatchResult>
     */
    private static function anchored(string $prefix, array $ranked, SmithWatermanMatcher $matcher): array
    {
        $matches = [];
        foreach ($ranked as $result) {
            if (($result->matchedIndices[0] ?? -1) === 0) {
                $matches[] = $result;
                continue;
            }

            $indices = self::anchoredPlacement($prefix, $result->haystack, $matcher);
            if ($indices !== null) {
                $matches[] = new MatchResult($result->needle, $result->haystack, $result->score, $indices);
            }
        }

        return $matches;
    }

    /**
     * A full-query placement of $prefix in $name whose first index is 0, or
     * null when none exists.
     *
     * One exists exactly when the first characters fold equal and the rest of
     * the query is an in-order subsequence of the rest of the name. The rest
     * is placed by the same full-query matcher (so it carries one index per
     * remaining query character, positioned the way the matcher would
     * highlight them) and shifted past the anchor. Folding is candy-fuzzy's
     * {@see CharFold}, the matcher's own, so this test and the matcher never
     * disagree about which characters are equal; both index spaces are code
     * points of the original string.
     *
     * @return list<int>|null
     */
    private static function anchoredPlacement(string $prefix, string $name, SmithWatermanMatcher $matcher): ?array
    {
        $head = CharFold::foldSplit(mb_substr($prefix, 0, 1, 'UTF-8'));
        if ($head === [] || $head !== CharFold::foldSplit(mb_substr($name, 0, 1, 'UTF-8'))) {
            return null;
        }

        $restQuery = mb_substr($prefix, 1, null, 'UTF-8');
        if ($restQuery === '') {
            return [0];
        }

        $rest = $matcher->match($restQuery, mb_substr($name, 1, null, 'UTF-8'));
        if ($rest === null) {
            return null;
        }

        return [0, ...array_map(static fn(int $index): int => $index + 1, $rest->matchedIndices)];
    }

    /**
     * Most prefixes {@see fuzzyMatches()} remembers per name list; past it the
     * oldest is forgotten, so a long-lived session's memo stays bounded.
     */
    private const MATCH_MEMO_LIMIT = 64;

    /**
     * The "/" popup's one matcher, built on first use with `requireFullQuery`
     * (candy-fuzzy #3) - the coverage rule this class used to hand-roll by
     * comparing `count($matchedIndices)` with the prefix length.
     */
    private static ?SmithWatermanMatcher $matcher = null;

    /**
     * Ranked, anchored matches ({@see anchored()}) per prefix for the name
     * list they were computed over (candy-fuzzy #2). The popup asks for its rows several times per
     * keystroke - the key arms, the menu-visibility guard, the renderer -
     * over a name list that does not change between keystrokes, so each
     * (prefix, names) pair is aligned once. A different name list (a custom
     * command file appearing, a caller passing its own rows) discards the
     * whole memo: results are only served for the exact list they came from.
     *
     * @var array{names: list<string>, results: array<string, list<MatchResult>>}|null
     */
    private static ?array $matchMemo = null;

    /**
     * @param list<string> $names
     * @return list<MatchResult>
     */
    private static function fuzzyMatches(string $prefix, array $names): array
    {
        if (self::$matchMemo === null || self::$matchMemo['names'] !== $names) {
            self::$matchMemo = ['names' => $names, 'results' => []];
        }

        if (array_key_exists($prefix, self::$matchMemo['results'])) {
            return self::$matchMemo['results'][$prefix];
        }

        if (count(self::$matchMemo['results']) >= self::MATCH_MEMO_LIMIT) {
            unset(self::$matchMemo['results'][array_key_first(self::$matchMemo['results'])]);
        }

        self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);

        return self::$matchMemo['results'][$prefix] = self::anchored(
            $prefix,
            array_values(self::$matcher->matchAll($prefix, $names)),
            self::$matcher,
        );
    }
}
