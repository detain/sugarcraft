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

        // These three have no "/name" branch in Chat::submit(), so advertising
        // them in the "/" popup would offer a command that does nothing.
        foreach (['new', 'model', 'docs'] as $paletteOnly) {
            $this->assertNotContains($paletteOnly, $slashNames);
        }

        $this->assertSame($slashNames, self::names(CommandRegistry::filter('')));
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
