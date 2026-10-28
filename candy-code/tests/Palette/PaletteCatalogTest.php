<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Palette;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\McpPromptCommands;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteCatalog;
use SugarCraft\Code\Palette\PaletteEntry;
use SugarCraft\Code\Palette\PaletteEntryKind;

/**
 * Regression pins for the C-EXT-5 core: the palette row list becomes the
 * slash-command superset while the action segment stays byte-identical.
 */
final class PaletteCatalogTest extends TestCase
{
    public function testActionRowsKeepOrderAndLabelsOfTheRegistry(): void
    {
        $rows = PaletteCatalog::rows([]);
        $actions = PaletteAction::all();

        self::assertNotSame([], $actions, 'the registry must declare palette actions');
        self::assertCount(count($actions), $rows);
        foreach ($actions as $index => $action) {
            $row = $rows[$index];
            self::assertSame(PaletteEntryKind::Action, $row->kind);
            self::assertSame($action->label(), $row->label);
            self::assertSame($action->category(), $row->category);
            self::assertSame($action->shortcut(), $row->shortcut);
            self::assertNull($row->badge, 'action rows never carry a provenance badge');
            self::assertNull($row->commandName, 'action rows are not command rows');
        }

        // Purity: the same input yields the same list, twice in a row.
        self::assertEquals($rows, PaletteCatalog::rows([]));
    }

    public function testSurvivorBuiltInGetsPrefillRow(): void
    {
        $specs = CommandRegistry::slashCommands();
        $status = null;
        foreach ($specs as $spec) {
            if ($spec->name === 'status') {
                $status = $spec;
            }
        }
        self::assertInstanceOf(CommandSpec::class, $status, 'status is a slash-only survivor');
        self::assertNull($status->paletteAction, 'status has no palette action to ride');

        $entry = self::prefillByName(PaletteCatalog::rows($specs), 'status');
        self::assertInstanceOf(PaletteEntry::class, $entry);
        self::assertSame(PaletteEntryKind::Prefill, $entry->kind);
        self::assertStringStartsWith('/status', $entry->label);
        self::assertNull($entry->badge, 'a built-in survivor carries no tier badge');
        self::assertSame($status->category, $entry->category);
        self::assertSame($status->shortcut, $entry->shortcut);
    }

    public function testSpecCarryingPaletteActionIsNotPrefilled(): void
    {
        $withAction = null;
        foreach (CommandRegistry::slashCommands() as $spec) {
            if ($spec->paletteAction !== null) {
                $withAction = $spec;
            }
        }
        self::assertInstanceOf(CommandSpec::class, $withAction, 'some slash-visible spec carries a palette action');

        $rows = PaletteCatalog::rows(CommandRegistry::slashCommands());
        self::assertNull(self::prefillByName($rows, $withAction->name), 'its command form must not be prefilled');

        $matches = array_values(array_filter(
            $rows,
            static fn(PaletteEntry $row): bool => $row->label === $withAction->label(),
        ));
        self::assertCount(1, $matches, 'the command appears exactly once, as its action row');
        self::assertSame(PaletteEntryKind::Action, $matches[0]->kind);
    }

    public function testFileCommandCarriesItsTierBadge(): void
    {
        $spec = CommandSpec::new(
            name: 'cext5a-file-cmd',
            description: 'Deploys the stack.',
            category: 'Custom',
            argumentHint: '<env>',
            template: 'body text',
            tier: 'user',
        );

        $entry = self::prefillByName(PaletteCatalog::rows([$spec]), 'cext5a-file-cmd');
        self::assertInstanceOf(PaletteEntry::class, $entry);
        self::assertSame(PaletteEntryKind::Prefill, $entry->kind);
        self::assertSame('/cext5a-file-cmd <env>', $entry->label);
        self::assertSame($spec->tierBadge(), $entry->badge, 'the badge is the spec tier badge verbatim');
        self::assertSame('cext5a-file-cmd', $entry->commandName);
        self::assertNull($entry->shortcut);
    }

    public function testMcpPromptCarriesMcpBadge(): void
    {
        $spec = CommandSpec::new(
            name: 'mcp__srv__prompt',
            description: 'Server authored prompt.',
            category: 'MCP',
            template: 'prompt body',
            tier: McpPromptCommands::TIER,
        );

        $entry = self::prefillByName(PaletteCatalog::rows([$spec]), 'mcp__srv__prompt');
        self::assertInstanceOf(PaletteEntry::class, $entry);
        self::assertSame($spec->tierBadge(), $entry->badge);
        self::assertNotNull($entry->badge);
        self::assertStringContainsString('mcp', $entry->badge);
    }

    public function testLabelOmitsSpaceWhenNoHint(): void
    {
        $noHint = CommandSpec::new(
            name: 'cext5a-bare',
            description: 'Nothing to hint.',
            category: 'Custom',
        );
        $emptyHint = CommandSpec::new(
            name: 'cext5a-bare',
            description: 'Nothing to hint.',
            category: 'Custom',
            argumentHint: '',
        );

        foreach ([$noHint, $emptyHint] as $spec) {
            $entry = self::prefillByName(PaletteCatalog::rows([$spec]), 'cext5a-bare');
            self::assertInstanceOf(PaletteEntry::class, $entry);
            self::assertSame('/cext5a-bare', $entry->label);
        }
    }

    public function testHiddenAndDuplicateRows(): void
    {
        $hidden = CommandSpec::new(
            name: 'cext5a-hidden',
            description: 'Not slash visible.',
            category: 'Custom',
            slashVisible: false,
        );
        $rows = PaletteCatalog::rows([$hidden]);
        self::assertNull(self::prefillByName($rows, 'cext5a-hidden'), 'hidden specs never reach the palette');

        $first = CommandSpec::new(
            name: 'cext5a-dupe',
            description: 'First wins.',
            category: 'Custom',
            tier: 'user',
        );
        $second = CommandSpec::new(
            name: 'cext5a-dupe',
            description: 'Second loses.',
            category: 'Custom',
            tier: 'project',
        );
        $rows = PaletteCatalog::rows([$first, $second]);
        $matches = array_values(array_filter(
            $rows,
            static fn(PaletteEntry $row): bool => $row->label === '/cext5a-dupe',
        ));
        self::assertCount(1, $matches, 'a repeated label dedupes to one row');
        self::assertSame($first->tierBadge(), $matches[0]->badge, 'first-seen label wins');
    }

    public function testPrefillNeverCollidesWithActionLabels(): void
    {
        $rows = PaletteCatalog::rows(CommandRegistry::slashCommands());

        $labels = [];
        foreach ($rows as $row) {
            $labels[] = $row->label;
            if ($row->kind === PaletteEntryKind::Action) {
                self::assertStringStartsNotWith('/', $row->label, 'action labels are bare words');
            } else {
                self::assertStringStartsWith('/', $row->label, 'prefill labels carry the slash prefix');
            }
        }
        self::assertCount(count($labels), array_unique($labels), 'the row list carries no duplicate labels');
        self::assertGreaterThan(count(PaletteAction::all()), count($rows), 'the superset is strictly larger than the action list');
    }

    private static function prefillByName(array $rows, string $name): ?PaletteEntry
    {
        foreach ($rows as $row) {
            if ($row->kind === PaletteEntryKind::Prefill && $row->commandName === $name) {
                return $row;
            }
        }

        return null;
    }
}
