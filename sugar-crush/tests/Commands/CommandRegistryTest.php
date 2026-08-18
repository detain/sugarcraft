<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Palette\PaletteAction;
use PHPUnit\Framework\TestCase;

final class CommandRegistryTest extends TestCase
{
    /** @return list<string> */
    private static function names(array $specs): array
    {
        return array_map(static fn(CommandSpec $spec): string => $spec->name, $specs);
    }

    public function testAllReturnsEveryDispatchedCommand(): void
    {
        $names = self::names(CommandRegistry::all());

        foreach (['compact', 'workflow', 'share', 'agents', 'memory', 'branch', 'rename', 'rewind', 'sessions', 'theme'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testAllIsTheSingleSourceBothSurfacesRead(): void
    {
        foreach (PaletteAction::cases() as $action) {
            $rows = array_filter(
                CommandRegistry::all(),
                static fn(CommandSpec $spec): bool => $spec->paletteAction === $action,
            );

            $this->assertCount(1, $rows, $action->name . ' must own exactly one registry row');
        }
    }

    public function testPaletteItemListIsDerivedFromTheRegistryInDeclaredOrder(): void
    {
        $fromRegistry = array_map(
            static fn(CommandSpec $spec): PaletteAction => $spec->paletteAction,
            CommandRegistry::paletteEntries(),
        );

        $this->assertSame($fromRegistry, PaletteAction::all());
        $this->assertSame(
            array_map(static fn(CommandSpec $spec): string => $spec->label(), CommandRegistry::paletteEntries()),
            array_map(static fn(PaletteAction $action): string => $action->label(), PaletteAction::all()),
        );
    }

    public function testPaletteActionMetadataComesFromItsRegistryRow(): void
    {
        $this->assertSame('Exit', PaletteAction::Exit->label());
        $this->assertSame('App', PaletteAction::Exit->category());
        $this->assertSame('Ctrl+C', PaletteAction::Exit->shortcut());
        $this->assertSame('exit', PaletteAction::Exit->spec()->name);
        $this->assertNull(PaletteAction::SwitchTheme->shortcut());
    }

    public function testForPaletteActionFindsTheOwningRow(): void
    {
        $spec = CommandRegistry::forPaletteAction(PaletteAction::SwitchSession);

        $this->assertNotNull($spec);
        $this->assertSame('sessions', $spec->name);
        $this->assertSame('Switch session', $spec->label());
    }

    public function testPaletteOnlyRowsAreHiddenFromTheSlashPopup(): void
    {
        $slashNames = self::names(CommandRegistry::slashCommands());

        // These two have no "/name" branch in Chat::submit(), so advertising
        // them in the "/" popup would offer a command that does nothing.
        // `model` used to be a third: crush_code.md Phase 4 item 1 gave it a
        // real branch, so it is now listed, and
        // SlashDispatchTest::testEverySlashVisibleRegistryRowHasALiveDispatchHandler()
        // is what holds this list and the dispatch together from now on -
        // moving a row into the popup without a handler reds THAT test, which
        // is the check this one could never be.
        foreach (['new', 'docs'] as $paletteOnly) {
            $this->assertNotContains($paletteOnly, $slashNames);
        }
        $this->assertContains('model', $slashNames);

        $this->assertSame($slashNames, self::names(CommandRegistry::filter('')));
    }

    /**
     * The two lists {@see CommandRegistry::filter()} and
     * {@see CommandRegistry::filterMatchResults()} return are one list, not two
     * that agree by inspection: `filter()` is `array_map` over the other one.
     * Asserted because the popup pairs row N of the first with row N of the
     * second when it highlights (crush_code.md Phase 4 item 5), and a pairing
     * by index is only safe while the lengths and the order are the same.
     */
    public function testMatchResultsLineUpWithTheSpecsRowForRow(): void
    {
        foreach (['', 'r', 're', 'rwd', 'm', 'zzz'] as $prefix) {
            $specs = CommandRegistry::filter($prefix);
            $results = CommandRegistry::filterMatchResults($prefix);

            $this->assertSame(
                self::names($specs),
                array_map(static fn($result): string => $result->haystack, $results),
                "filter({$prefix}) and filterMatchResults({$prefix}) must be the same rows in the same order",
            );
        }
    }

    /**
     * `filter()` rebuilds its specs by keying `slashCommands()` on NAME
     * (`$byName[$spec->name]`) and looking each `MatchResult` haystack up in
     * that map, which is only equivalent to the row list while names are
     * unique. They are - and this is the assertion that keeps it that way.
     *
     * Without it the drift is invisible: two rows sharing a name would make
     * `filter()` return the LATER spec twice while `filterMatchResults()` kept
     * both rows, and
     * {@see testMatchResultsLineUpWithTheSpecsRowForRow()} would not notice,
     * because both sides of that comparison are haystack names and both would
     * read the same.
     */
    public function testCommandNamesAreUniqueBecauseFilterKeysOnThem(): void
    {
        $names = self::names(CommandRegistry::all());

        $this->assertSame(
            array_values(array_unique($names)),
            $names,
            'two rows with the same name would make CommandRegistry::filter() return one of them twice',
        );
    }

    /**
     * A bare "/" carries no matched characters, so the popup paints plain names
     * rather than one fully-highlighted row per advertised command - the same
     * contract the Ctrl+P palette's empty query has, and the reason Highlighter
     * no-ops there.
     *
     * The row count is derived from the registry below rather than written into
     * this sentence: a command count in a docblock or a test literal is exactly
     * the number that goes stale the next time a row is added, which is the rule
     * `handleHelpCommand()` states and the literal that used to stand here
     * ("19") broke.
     */
    public function testAnEmptyPrefixYieldsIndexLessMatchResults(): void
    {
        $results = CommandRegistry::filterMatchResults('');

        $this->assertCount(
            count(CommandRegistry::slashCommands()),
            $results,
            'fixture: the empty prefix lists every slash command, whatever there is now',
        );
        // assertSame([], …) and assertTrue(isEmpty()) are one assertion, not
        // two: MatchResult::isEmpty() IS `matchedIndices === []`, so the second
        // could never fail once the first passed. Kept the one that names the
        // row it is talking about in its message.
        foreach ($results as $result) {
            $this->assertSame([], $result->matchedIndices, "/{$result->haystack} must carry no matched indices");
        }

        // And a typed prefix does carry them, so the assertion above is about
        // the empty prefix rather than about the seam always being empty.
        $this->assertSame([0, 1], CommandRegistry::filterMatchResults('re')[0]->matchedIndices);
    }

    public function testMcpIsAdvertisedInTheSlashPopup(): void
    {
        // Regression: "mcp" used to be palette-only because dispatch was a
        // bare "mcp auth …" string with no leading slash, leaving /mcp
        // undiscoverable from the "/" popup.
        $this->assertContains('mcp', self::names(CommandRegistry::slashCommands()));
        $this->assertSame(['mcp'], self::names(CommandRegistry::filter('mcp')));
    }

    public function testLabelFallsBackToTheCommandName(): void
    {
        $spec = CommandSpec::new('compact', 'Compact history', 'Session');

        $this->assertSame('compact', $spec->label());
        $this->assertNull($spec->paletteAction);
        $this->assertTrue($spec->slashVisible);
    }

    public function testCommandsThatTakeArgumentsCarryAHint(): void
    {
        $hints = [];
        foreach (CommandRegistry::all() as $spec) {
            $hints[$spec->name] = $spec->argumentHint;
        }

        $this->assertSame('<name>', $hints['rename']);
        $this->assertSame('[format] [expiry]', $hints['share']);
        $this->assertNull($hints['compact']);
    }

    public function testFilterIsFuzzyNotJustAPrefixMatch(): void
    {
        // The failure mode this replaced: a plain str_starts_with() filter
        // returned nothing for "rwd", so /rewind was unreachable by typo.
        $this->assertSame(['rewind'], self::names(CommandRegistry::filter('rwd')));
    }

    public function testFilterIsCaseInsensitiveAndKeepsPrefixMatches(): void
    {
        $this->assertSame(['rename', 'rewind'], self::names(CommandRegistry::filter('RE')));
        $this->assertSame(['rename', 'rewind'], self::names(CommandRegistry::filter('re')));
    }

    public function testFilterDropsCandidatesThatOnlyPartiallyMatch(): void
    {
        // "agents" scores against "re" on its "e" alone under the matcher's
        // local alignment; a partial hit must not widen the popup.
        $this->assertNotContains('agents', self::names(CommandRegistry::filter('re')));
    }

    public function testFilterWithEmptyPrefixReturnsEverySlashCommand(): void
    {
        $this->assertCount(count(CommandRegistry::slashCommands()), CommandRegistry::filter(''));
    }

    public function testFilterWithNoMatchesReturnsEmptyList(): void
    {
        $this->assertSame([], CommandRegistry::filter('zzz'));
    }
}
