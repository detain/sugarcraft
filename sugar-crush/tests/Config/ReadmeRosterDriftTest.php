<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\ParsedArgs;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tools\Tool;

/**
 * `README.md` IS THE ONE DOCUMENT A USER ACTUALLY READS, and until round 42 not
 * one sentence of it was pinned by anything. `docs/PERMISSIONS.md` and
 * `docs/SETTINGS.md` have {@see TrustKeyDocumentationDriftTest};
 * {@see ReadmeSettingsTierClaimTest} then covered the README's settings-tier
 * paragraph and nothing else. That asymmetry is backwards — a false claim
 * survives longest in the file with the most readers and the fewest checks,
 * which is exactly how a retracted SECURITY sentence lived through two rounds
 * while `src/` and `docs/` already disagreed with it.
 *
 * WHAT THIS FILE IS. Not a proof-read of the README. It pins the ROSTERS — the
 * enumerations a reader counts and a maintainer forgets: the tool set, the
 * slash commands, the subcommands, the layer table, the permission modes, the
 * chat-owned control chords, and the launch-report sample. Each has a
 * generator in `src/`, so each can be re-derived rather than believed.
 *
 * WHY ROSTERS AND NOT PROSE. An enumeration has a cheap oracle and a silent
 * failure mode: a twelfth tool or a twenty-second command lands, nobody edits
 * the README, and the page stays plausible. Argument prose has neither — no
 * oracle, and a wrong argument usually reads wrong. The full inventory,
 * including the claims deliberately left unpinned and why, is in this lane's
 * round-43 report; the short version is in
 * {@see testTheReadmeStillContainsEveryRosterThisFilePins()}, which fails if a
 * section this file depends on is renamed away, so a roster cannot go
 * unchecked by quietly disappearing.
 *
 * READING RULE FOR EVERY ASSERTION HERE: the README is the document, and the
 * test bends to it. Nothing below asks the page to be machine-readable, carry a
 * marker comment, or spell a name the way a constant does. Each locator is a
 * heading or a phrase a human wrote for humans, and each is asserted to
 * identify exactly ONE region — a locator that stops being unique reds instead
 * of silently pointing at the wrong paragraph, which is the failure round 42's
 * mutation C and this lane's own first draft both hit.
 *
 * @internal
 */
final class ReadmeRosterDriftTest extends TestCase
{
    private const README = __DIR__ . '/../../README.md';

    private function readme(): string
    {
        $text = (string) file_get_contents(self::README);
        self::assertNotSame('', $text, 'README.md is empty or unreadable');

        return $text;
    }

    /** The README with every run of whitespace collapsed — claims are line-wrapped. */
    private function flat(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->readme());
    }

    /**
     * The body of one `###`/`##` section, located by its heading.
     *
     * A SECTION, not a character window. Round 42 measured a ±N-character
     * window surviving the mutation it existed to catch, because a window wide
     * enough to reach the thing it looks for is wide enough for a restored
     * false sentence to sit inside it. A heading-delimited section is a unit
     * the author already chose; it moves when the document is restructured,
     * which is when this test SHOULD be re-read.
     */
    private function section(string $heading): string
    {
        $readme = $this->readme();
        $quoted = preg_quote($heading, '/');

        $matched = preg_match('/^#{2,4} ' . $quoted . '\s*$(.*?)(?=^#{1,4} |\z)/ms', $readme, $m);
        $this->assertSame(1, $matched, 'README.md has no section headed "' . $heading . '"');

        return $m[1];
    }

    // ── roster 1: the built-in tool set ──────────────────────────────────

    /**
     * The eleven built-in tools, as the model sees them.
     *
     * `Bootstrap::tools()` rather than a `glob()` of `src/Tools/BuiltIn/`: the
     * README's list is of what a launch SHIPS, and the two are not the same
     * question — the class file is named `LspTool.php` and `SkillTool.php`
     * while the tool answers to `Lsp` and `Skill`, and `Doctor.php` answers to
     * a lower-case `doctor`. A filename census would have this test asserting
     * the README documents PHP classes, which is not what it says.
     *
     * @return list<string>
     */
    private function shippedToolNames(): array
    {
        $names = array_map(
            static fn(Tool $t): string => $t->name(),
            Bootstrap::tools(\dirname(__DIR__, 2)),
        );
        sort($names);

        return $names;
    }

    public function testTheCapabilitiesToolRosterNamesEveryToolALaunchShips(): void
    {
        $shipped = $this->shippedToolNames();
        $this->assertGreaterThan(3, \count($shipped), 'the tool census is too small to say anything');

        $section = (string) preg_replace('/\s+/', ' ', $this->section('Capabilities'));

        // SET EQUALITY, not presence. A presence loop passes for a tool that has
        // been REMOVED from the launch but is still named somewhere in a section
        // this long, which is the direction that leaves a user configuring a
        // tool that no longer exists. The roster is the run of backticked tokens
        // in the Tools bullet up to the sentence that ends the list — located by
        // that sentence rather than by a token count, so adding a twelfth tool
        // moves the boundary with it.
        $matched = preg_match(
            '/- \*\*Tools\*\* — `Tools\\\\BuiltIn\\\\\*`:(.*?)These are \*\*runtime tool names\*\*/',
            $section,
            $m,
        );
        $this->assertSame(1, $matched, "README.md's Tools bullet no longer opens with a locatable roster");

        // Drop the parenthesised asides — `$SUGARCRUSH_SEARCH_ENDPOINT` is a
        // gloss on WebSearch, not a twelfth tool.
        $rosterText = (string) preg_replace('/\([^)]*\)/', '', $m[1]);
        preg_match_all('/`([A-Za-z_]+)`/', $rosterText, $tokens);
        $listed = array_values(array_unique($tokens[1]));
        sort($listed);

        $this->assertSame(
            $shipped,
            $listed,
            "README.md's Tools roster and the tool set Bootstrap::tools() ships disagree",
        );

        // AND THE COUNT, spelled out in words, because that is how the page
        // writes it and a word is the cheapest thing to leave stale. The
        // sibling figure — "disabled 10 of the 11" in the launch-report sample
        // — is pinned by ReadmeSettingsTierClaimTest and not duplicated here.
        $spelled = [11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 10 => 'Ten'];
        $this->assertArrayHasKey(
            \count($shipped),
            $spelled,
            'the tool count left the range this assertion knows how to spell; extend the map',
        );
        $this->assertStringContainsString(
            $spelled[\count($shipped)] . ' classes',
            $section,
            'README.md counts the built-in tools out loud and the number is now wrong',
        );
    }

    // ── roster 2: the slash commands ─────────────────────────────────────

    /**
     * The advertised names and the parenthesised aliases, read off the opening
     * roster of the `### Slash commands` section.
     *
     * The roster is the run of backticked `/name` tokens before the first blank
     * line — everything after it is prose that legitimately mentions individual
     * commands, and swallowing that prose is how a whole-section scan would
     * report `/rwd` (a fuzzy-match example) as an undocumented command.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function readmeSlashRoster(): array
    {
        $section = ltrim($this->section('Slash commands'));
        $roster = (string) preg_split('/\n\s*\n/', $section)[0];
        $flat = (string) preg_replace('/\s+/', ' ', $roster);

        preg_match_all('/(\()?`\/([a-z]+)`/', $flat, $m, PREG_SET_ORDER);

        $advertised = [];
        $aliases = [];
        foreach ($m as $hit) {
            if ($hit[1] === '(') {
                $aliases[] = $hit[2];

                continue;
            }
            $advertised[] = $hit[2];
        }
        sort($advertised);
        sort($aliases);

        return [$advertised, $aliases];
    }

    public function testTheSlashCommandRosterIsExactlyWhatTheRegistryAdvertises(): void
    {
        [$advertised] = $this->readmeSlashRoster();

        $registry = array_map(static fn(CommandSpec $s): string => $s->name, CommandRegistry::slashCommands());
        sort($registry);

        $this->assertNotSame([], $registry, 'the registry is empty, so this assertion would be vacuous');
        $this->assertSame(
            $registry,
            $advertised,
            "README.md's slash-command roster and CommandRegistry::slashCommands() disagree",
        );
    }

    /**
     * And the parenthesised spellings are exactly the aliases that dispatch
     * without a registry row. The README says so in the sentence right after
     * the roster — "they dispatch, but they have no `CommandRegistry` row of
     * their own" — which is a checkable claim in both directions.
     *
     * The alias census comes from `Chat::dispatchCommand()`'s own match arms,
     * the same source
     * {@see \SugarCraft\Crush\Tests\Commands\SlashDispatchTest} derives its
     * inventory from, rather than from a list retyped here — a hand-kept list
     * would be exactly as blind to a fourth alias as the README is.
     */
    public function testTheParenthesisedSpellingsAreExactlyTheUnadvertisedAliases(): void
    {
        [$advertised, $aliases] = $this->readmeSlashRoster();

        $registry = array_map(static fn(CommandSpec $s): string => $s->name, CommandRegistry::slashCommands());

        $arms = [];
        $source = (string) file_get_contents((string) (new \ReflectionClass(\SugarCraft\Crush\Chat::class))->getFileName());
        if (preg_match('/function dispatchCommand\(.*?\n    \}/s', $source, $m) === 1) {
            preg_match_all("/'([a-z]+)'\s*(?=,\s*'|=>)/", $m[0], $armMatches);
            $arms = array_values(array_unique($armMatches[1]));
        }
        $this->assertNotSame([], $arms, 'the dispatch-arm extractor found nothing, so this assertion is vacuous');

        $unadvertised = array_values(array_diff($arms, $registry));
        sort($unadvertised);

        $this->assertSame(
            $unadvertised,
            $aliases,
            "README.md's parenthesised aliases and Chat::dispatchCommand()'s unadvertised arms disagree",
        );
        $this->assertSame([], array_intersect($aliases, $advertised), 'an alias is also listed as an advertised row');
    }

    // ── roster 3: the subcommands ────────────────────────────────────────

    public function testTheSubcommandFenceNamesEveryVerbTheParserAccepts(): void
    {
        class_exists(ArgvParser::class);
        $section = $this->section('Subcommands');

        preg_match_all('/^sugarcrush ([a-z]+)/m', $section, $m);
        $documented = array_values(array_unique($m[1]));
        sort($documented);

        $accepted = ParsedArgs::SUBCOMMANDS;
        sort($accepted);

        $this->assertSame(
            $accepted,
            $documented,
            "README.md's subcommand fence and ParsedArgs::SUBCOMMANDS disagree",
        );
    }

    // ── roster 4: the settings layer table ───────────────────────────────

    /**
     * The four-row precedence table, HIGHEST FIRST as the page prints it.
     *
     * Pinned against the two path constants and the merge's own argument order
     * rather than against a retyped list. The table is the first thing a user
     * reads when a setting does not take effect, and a row in the wrong place
     * is worse than a missing row: it sends them to edit a file that cannot
     * win.
     */
    public function testTheLayerTableRanksTheFilesInTheOrderTheMergeActuallyUses(): void
    {
        $section = $this->section('Settings files');

        preg_match_all('/^\| (\d) \| `([^`]+)` \|/m', $section, $m, PREG_SET_ORDER);
        $rows = array_map(static fn(array $r): array => [$r[1], $r[2]], $m);

        $this->assertSame(
            [
                ['4', '~/.sugar-crush/config.json'],
                ['3', '~/.sugar-crush/' . LayeredSettings::USER_FILE],
                ['2', '<project>/' . LayeredSettings::LOCAL_PATH],
                ['1', '<project>/' . LayeredSettings::SHARED_PATH],
            ],
            $rows,
            "README.md's layer table no longer matches the layers LayeredSettings implements",
        );

        // The ranking itself, measured rather than read off the table: layer 4
        // beats 3 beats 1/2. `merge()` takes them lowest-first, so a swapped
        // argument order reds here and the table above becomes a lie.
        $merged = LayeredSettings::merge(
            ['theme' => 'layer4'],
            ['theme' => 'layer3', 'titleModel' => 'layer3'],
            ['theme' => 'layer1', 'titleModel' => 'layer1', 'disabledSkills' => ['layer1']],
        );
        $this->assertSame('layer4', $merged['theme']);
        $this->assertSame('layer3', $merged['titleModel']);
        $this->assertSame(['layer1'], $merged['disabledSkills']);
    }

    // ── roster 5: the permission modes ───────────────────────────────────

    /**
     * The README names the six modes in one sentence. An enum case added
     * without a README edit ships a mode no page mentions; a mode REMOVED
     * leaves the page offering a value the launch refuses, which is the more
     * annoying direction because the user gets exit 2 for following the docs.
     */
    public function testTheReadmeNamesEveryPermissionMode(): void
    {
        $flat = $this->flat();

        $cases = array_map(static fn(PermissionMode $m): string => $m->value, PermissionMode::cases());
        $this->assertGreaterThan(2, \count($cases), 'the mode census is too small to say anything');

        foreach ($cases as $mode) {
            $this->assertStringContainsString(
                '`' . $mode . '`',
                $flat,
                'README.md never names the permission mode `' . $mode . '`',
            );
        }

        // The other direction: the sentence that lists them all must list
        // exactly them, so a removed mode cannot linger. Located by the
        // enumerating phrase, and asserted to be unique so the window cannot
        // drift onto some other sentence that happens to name a mode.
        $matched = preg_match_all('/runs this launch\s+under one of ([^.]+)\./', $flat, $m);
        $this->assertSame(1, $matched, 'the mode-enumerating sentence is no longer uniquely identifiable');

        preg_match_all('/`([a-z-]+)`/', $m[1][0], $listed);
        $inSentence = $listed[1];
        sort($inSentence);
        $expected = $cases;
        sort($expected);

        $this->assertSame($expected, $inSentence, 'the README sentence that enumerates the modes is out of date');
    }

    // ── roster 6: who owns the control chords ────────────────────────────

    /**
     * "`Ctrl+P`, `Ctrl+O`, `Ctrl+A`, `Ctrl+W` and `Ctrl+C` always belong to the
     * chat content model — the shell never claims them", with `Ctrl+R` as the
     * ONE declared exception.
     *
     * This is the only part of the Keys section with a cheap exact oracle, and
     * it is the part worth having one: it is a claim about what CANNOT happen
     * ("hosting chat inside the shell cannot silently steal a binding"), and a
     * claim of that shape is the kind a reader has no way to check.
     * {@see KeyBindingRegistry::chatCtrlRunes()} and
     * {@see KeyBindingRegistry::chatCtrlRunesYieldedToShell()} are the sets
     * `Tui\KeyboardHandler` routes on, so the prose and the router are pinned
     * to each other rather than to a list written down twice.
     *
     * The Keys TABLE itself is deliberately not pinned row-by-row — see this
     * lane's report — because the README spells chords for humans (`Up`,
     * `Page Up`) and the registry spells them for the screen (`↑`, `PgUp`), so
     * any mapping between them is a translation table that would itself go
     * stale. `tests/Commands/KeyBindingDriftTest.php` presses the in-app
     * reference, which the README names as the authority.
     */
    public function testTheReadmeNamesExactlyTheChordsTheShellNeverClaims(): void
    {
        $flat = $this->flat();

        $matched = preg_match('/((?:`Ctrl\+[A-Z]`(?:, | and )?)+) always belong to the chat\s*content model/', $flat, $m);
        $this->assertSame(1, $matched, 'the always-chat chord sentence is gone or no longer identifiable');

        preg_match_all('/`Ctrl\+([A-Z])`/', $m[1], $listed);
        $claimed = array_map('strtolower', $listed[1]);
        sort($claimed);

        $always = array_values(array_diff(
            KeyBindingRegistry::chatCtrlRunes(),
            KeyBindingRegistry::chatCtrlRunesYieldedToShell(),
        ));
        sort($always);

        $this->assertNotSame([], $always, 'the chat-owned rune set is empty, so this assertion would be vacuous');
        $this->assertSame(
            $always,
            $claimed,
            'README.md names a different set of always-chat control chords than KeyBindingRegistry routes',
        );

        // And the exception is named, and is the only one.
        $yielded = KeyBindingRegistry::chatCtrlRunesYieldedToShell();
        sort($yielded);
        $this->assertSame(['r'], $yielded, 'the yielded-chord set changed; the README exception sentence must too');
        $this->assertStringContainsString(
            '`Ctrl+R` belongs to the chat too, with one declared exception',
            $flat,
            'README.md no longer declares Ctrl+R as the single exception',
        );
    }

    // ── roster 7: the launch-report sample ───────────────────────────────

    /**
     * The sample output block is a TRANSCRIPT of a real line, so it can be
     * regenerated rather than proof-read. `Bootstrap::reportProjectTierToolRemovals()`
     * builds it with one `sprintf()`; this rebuilds the same string from the
     * live tool census and asserts the page still prints it.
     *
     * The FORMAT is duplicated here rather than reached through reflection, and
     * that is a deliberate cost: `Bootstrap` is not this lane's file, the
     * method is private, and a test that reached into it would pin the
     * implementation instead of the output. The duplication is safe in the
     * direction that matters — change the sprintf and this reds, which is the
     * point. What it cannot catch is the two being edited together while the
     * README is not; `ReadmeSettingsTierClaimTest` covers the counts in that
     * line from the other side.
     */
    public function testTheLaunchReportSampleIsStillTheLineTheLauncherWouldPrint(): void
    {
        $ceiling = $this->shippedToolNames();

        // The sample's own scenario: the counterexample glob, which leaves Bash.
        $removed = array_values(array_filter(
            $ceiling,
            static fn(string $n): bool => fnmatch('[!B]*', $n),
        ));
        $remaining = array_values(array_diff($ceiling, $removed));

        $this->assertSame(['Bash'], $remaining, 'the counterexample no longer leaves exactly Bash');

        // Order as the launcher emits it: the census order, not sorted. The
        // README sample prints Read, Edit, Glob, Grep, Write, ... which is
        // Bootstrap::tools()' construction order with Bash lifted out.
        $emitted = array_values(array_filter(
            array_map(
                static fn(Tool $t): string => $t->name(),
                Bootstrap::tools(\dirname(__DIR__, 2)),
            ),
            static fn(string $n): bool => fnmatch('[!B]*', $n),
        ));

        $expected = sprintf(
            '(disabledTools) disabled %d of the %d tools your own settings left — %s — leaving: %s.',
            \count($removed),
            \count($ceiling),
            implode(', ', $emitted),
            implode(', ', $remaining),
        );

        $this->assertStringContainsString(
            $expected,
            $this->flat(),
            "README.md's launch-report sample is no longer the line the launcher would print",
        );
    }

    // ── the meta-check ───────────────────────────────────────────────────

    /**
     * EVERY LOCATOR THIS FILE DEPENDS ON, ASSERTED TO STILL RESOLVE.
     *
     * Without this, the failure mode of a section-scoped test is silence: rename
     * `### Slash commands`, and `section()` reds — good — but delete the section
     * outright and a lazier locator would simply match nothing and pass. Each
     * roster above uses `assertSame(1, preg_match(...))` for its own locator; this
     * gathers them so a restructuring of the page produces ONE readable failure
     * naming what moved, rather than six.
     */
    public function testTheReadmeStillContainsEveryRosterThisFilePins(): void
    {
        foreach (['Capabilities', 'Slash commands', 'Subcommands', 'Settings files', 'Keys'] as $heading) {
            $this->assertNotSame(
                '',
                trim($this->section($heading)),
                'README.md section "' . $heading . '" is now empty',
            );
        }
    }
}
