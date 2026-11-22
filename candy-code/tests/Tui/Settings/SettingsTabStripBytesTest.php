<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Settings\SettingsEditor;
use SugarCraft\Code\Tui\Settings\SettingsSources;
use SugarCraft\Code\Tui\Settings\SettingsTabStrip;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Style\Style;

/**
 * Byte pins for the settings view's category strip. The bytes are the frames
 * the strip drew with its own scroll arithmetic before it moved onto the
 * sugar-widgets Tabs library (P2-2), so any drift in the move goes red here.
 * The ambient Style profile is pinned to its resting TrueColor state so a
 * sibling test that negotiated another profile cannot change the bytes.
 */
final class SettingsTabStripBytesTest extends TestCase
{
    private const STRIP_MARK = "\e[38;2;139;143;168m";
    private const TAB_DIVIDER = "\e[38;2;20;20;20m│\e[0m";
    private const STRIP_ACTIVE = "\e[1;7m\e[38;2;112;145;245m";
    private const STRIP_INACTIVE = "\e[38;2;197;201;212m";
    private const BORDER_LEFT = "\e[38;2;112;145;245m│\e[0m ";
    private const BORDER_RIGHT = "\e[38;2;112;145;245m│\e[0m";
    private const FIRST_WINDOW_ZONES = [
        [2, 2, 20, 'settings:tab:0'],
        [2, 21, 33, 'settings:tab:1'],
        [2, 34, 56, 'settings:tab:2'],
        [2, 57, 70, 'settings:tab:3'],
    ];

    public function testAFixedLabelSetThatFitsDrawsEveryTabWithSeparators(): void
    {
        self::assertSame(
            [
                self::STRIP_INACTIVE . " Model \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_ACTIVE . " Providers \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Permissions \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Keys \e[0m",
                [[0, 7, 0], [8, 19, 1], [20, 33, 2], [34, 40, 3]],
            ],
            $this->render(['Model', 'Providers', 'Permissions', 'Keys'], 1, 80),
        );
    }

    public function testAnOverflowingStripScrollsToTheActiveTabAndMarksBothEdges(): void
    {
        $labels = ['Model', 'Providers', 'Permissions', 'Appearance', 'Keys', 'MCP'];

        self::assertSame(
            [
                self::STRIP_MARK . "‹\e[0m" . self::STRIP_ACTIVE . " Appearance \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Keys \e[0m" . self::STRIP_MARK . "›\e[0m",
                [[1, 13, 3], [14, 20, 4]],
            ],
            $this->render($labels, 3, 24),
        );
        self::assertSame(
            [
                self::STRIP_MARK . "‹\e[0m" . self::STRIP_INACTIVE . " Keys \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_ACTIVE . " MCP \e[0m",
                [[1, 7, 4], [8, 13, 5]],
            ],
            $this->render($labels, 5, 24),
            'scrolled to the last tab only the left mark is drawn',
        );
        self::assertSame(
            [self::STRIP_ACTIVE . " Model \e[0m" . self::STRIP_MARK . "›\e[0m", [[0, 7, 0]]],
            $this->render(['Model', 'Providers', 'Permissions'], 0, 18),
            'tabs hidden on the right end in the right mark',
        );
    }

    /**
     * The local narrow-width guard: an active tab wider than the strip with
     * its marks draws the marks alone, with no tab and no hit span; one cell
     * more and the tab is drawn.
     */
    public function testAnActiveTabWiderThanTheStripDrawsOnlyTheMarks(): void
    {
        $labels = ['Model', 'Providers', 'Permissions'];

        self::assertSame(
            [self::STRIP_MARK . "‹\e[0m" . self::STRIP_MARK . "›\e[0m", []],
            $this->render($labels, 1, 12),
        );
        self::assertSame(
            [self::STRIP_MARK . "‹\e[0m" . self::STRIP_ACTIVE . " Providers \e[0m" . self::STRIP_MARK . "›\e[0m", [[1, 12, 1]]],
            $this->render($labels, 1, 13),
            'at the exact fit the tab and its span are drawn',
        );
        self::assertSame(
            [self::STRIP_MARK . "›\e[0m", []],
            $this->render(['Providers'], 0, 10),
            'with nothing to its left the guard draws the right mark alone',
        );
        self::assertSame(
            [self::STRIP_MARK . "‹\e[0m" . self::STRIP_MARK . "›\e[0m", []],
            $this->render(['Model', 'Providers'], 1, 11),
            'an active last tab still draws the right mark: the guard draws it unconditionally',
        );
    }

    public function testTheEditorPaintsTheStripAndItsZonesFromTheSameFrame(): void
    {
        $this->inEnglish(function (): void {
            $editor = SettingsEditor::open(self::sources());

            self::assertSame(
                self::BORDER_LEFT . self::STRIP_ACTIVE . " Model & Provider \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Agent loop \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Context & Compaction \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Permissions \e[0m" . self::STRIP_MARK . "›\e[0m        " . self::BORDER_RIGHT,
                $this->stripLine($editor, 80),
            );
            self::assertSame(self::FIRST_WINDOW_ZONES, $this->tabZones($editor, 80));

            self::assertSame(
                self::BORDER_LEFT . self::STRIP_ACTIVE . " Model & Provider \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Agent loop \e[0m" . self::STRIP_MARK . "›\e[0m     " . self::BORDER_RIGHT,
                $this->stripLine($editor, 40),
            );
            self::assertSame(
                [[2, 2, 20, 'settings:tab:0'], [2, 21, 33, 'settings:tab:1']],
                $this->tabZones($editor, 40),
            );
        });
    }

    public function testLeftAndRightSwitchTabsAndRepaintTheStrip(): void
    {
        $this->inEnglish(function (): void {
            $editor = SettingsEditor::open(self::sources());

            $right = $editor->update(new KeyMsg(KeyType::Right));
            self::assertNotNull($right);
            self::assertSame(
                self::BORDER_LEFT . self::STRIP_INACTIVE . " Model & Provider \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_ACTIVE . " Agent loop \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Context & Compaction \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Permissions \e[0m" . self::STRIP_MARK . "›\e[0m        " . self::BORDER_RIGHT,
                $this->stripLine($right, 80),
            );
            self::assertSame(self::FIRST_WINDOW_ZONES, $this->tabZones($right, 80));

            $left = $editor->update(new KeyMsg(KeyType::Left));
            self::assertNotNull($left);
            self::assertTrue($left->onFilesTab(), 'Left from the first tab wraps to the last');
            self::assertSame(
                self::BORDER_LEFT . self::STRIP_MARK . "‹\e[0m" . self::STRIP_INACTIVE . " Git & Automation \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Interface \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Hooks & MCP \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Server \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_INACTIVE . " Advanced \e[0m" . self::TAB_DIVIDER
                    . self::STRIP_ACTIVE . " Files \e[0m    " . self::BORDER_RIGHT,
                $this->stripLine($left, 80),
            );
            self::assertSame(
                [
                    [2, 3, 21, 'settings:tab:9'],
                    [2, 22, 33, 'settings:tab:10'],
                    [2, 34, 47, 'settings:tab:11'],
                    [2, 48, 56, 'settings:tab:12'],
                    [2, 57, 67, 'settings:tab:13'],
                    [2, 68, 75, 'settings:tab:14'],
                ],
                $this->tabZones($left, 80),
            );
        });
    }

    /**
     * @param list<string> $labels
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int}>}
     */
    private function render(array $labels, int $active, int $width): array
    {
        return Style::withDefaultProfile(
            null,
            static fn (): array => SettingsTabStrip::render($labels, $active, $width, Theme::dark()),
        );
    }

    private static function sources(): SettingsSources
    {
        return SettingsSources::fromLaunch('/nonexistent-root', null, null, null, ['CANDY_CODE_PROVIDER' => 'dev-sglang']);
    }

    private function inEnglish(callable $fn): void
    {
        Lang::inLocale('en', static fn () => Style::withDefaultProfile(null, $fn));
    }

    private function stripLine(SettingsEditor $editor, int $cols): string
    {
        return explode("\n", $editor->view(Theme::dark(), $cols, 24))[2];
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: string}> */
    private function tabZones(SettingsEditor $editor, int $cols): array
    {
        return array_values(array_filter(
            $editor->zones(Theme::dark(), $cols, 24),
            static fn (array $zone): bool => str_starts_with($zone[3], SettingsEditor::TAB_ZONE),
        ));
    }
}
