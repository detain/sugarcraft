<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\MediaForm\FormTabs;
use SugarCraft\Code\Tui\MediaForm\GenFormKeys;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Style\Style;

/**
 * Byte pins for the generate form's section strip. The bytes are the frames
 * the strip drew through SettingsTabStrip before it moved onto the
 * sugar-widgets Tabs library (P2-2), so any drift in the move goes red here.
 * The ambient Style profile is pinned to its resting TrueColor state so a
 * sibling test that negotiated another profile cannot change the bytes.
 */
final class FormTabsTest extends TestCase
{
    private const MUTED = "\e[38;2;139;143;168m";
    private const DIVIDER = "\e[38;2;20;20;20m│\e[0m";
    private const ACTIVE = "\e[1;7m\e[38;2;112;145;245m";
    private const INACTIVE = "\e[38;2;197;201;212m";
    private const FRAME_LEFT = "\e[38;2;112;145;245m│\e[0m ";
    private const FRAME_RIGHT = "\e[38;2;112;145;245m│\e[0m";
    private const FIRST_WINDOW_ZONES = [
        [1, 2, 10, 'media:tab:0'],
        [1, 11, 30, 'media:tab:1'],
        [1, 31, 38, 'media:tab:2'],
        [1, 39, 51, 'media:tab:3'],
    ];

    public function testEmptyLabelsOrNoWidthDrawNothing(): void
    {
        self::assertSame(['', []], $this->render([], 0, 80));
        self::assertSame(['', []], $this->render(['A'], 0, 0));
        self::assertSame(['', []], $this->render(['A'], 0, -3));
    }

    public function testAFixedLabelSetThatFitsDrawsEveryTabWithSeparators(): void
    {
        self::assertSame(
            [
                self::INACTIVE . " Prompt \e[0m" . self::DIVIDER
                    . self::ACTIVE . " Generation params \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Extra \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Hires \e[0m",
                [[0, 8, 0], [9, 28, 1], [29, 36, 2], [37, 44, 3]],
            ],
            $this->render(['Prompt', 'Generation params', 'Extra', 'Hires'], 1, 80),
        );
    }

    public function testAnOverflowingStripMarksBothEdgesWithoutSeparators(): void
    {
        self::assertSame(
            [
                self::MUTED . "‹\e[0m" . self::ACTIVE . " Three \e[0m" . self::MUTED . "›\e[0m",
                [[1, 8, 2]],
            ],
            $this->render(['One', 'Two', 'Three', 'Four', 'Five'], 2, 14),
        );
    }

    public function testScrolledToTheLastTabOnlyTheLeftMarkIsDrawn(): void
    {
        self::assertSame(
            [self::MUTED . "‹\e[0m" . self::ACTIVE . " Four \e[0m", [[1, 7, 3]]],
            $this->render(['One', 'Two', 'Three', 'Four'], 3, 12),
        );
    }

    public function testTabsHiddenOnTheRightEndInTheRightMark(): void
    {
        self::assertSame(
            [
                self::ACTIVE . " One \e[0m" . self::DIVIDER . self::INACTIVE . " Two \e[0m" . self::MUTED . "›\e[0m",
                [[0, 5, 0], [6, 11, 1]],
            ],
            $this->render(['One', 'Two', 'Three', 'Four'], 0, 12),
        );
    }

    /**
     * The local narrow-width guard: an active tab wider than the strip draws
     * the marks alone, with no tab and no hit span.
     */
    public function testAnActiveTabWiderThanTheStripDrawsOnlyTheMarks(): void
    {
        Lang::inLocale('en', function (): void {
            $labels = [
                Lang::t(GenFormKeys::SECTION_PROMPT),
                Lang::t(GenFormKeys::SECTION_PARAMS),
                Lang::t(GenFormKeys::SECTION_EXTRA),
            ];
            self::assertSame('Generation params', $labels[1]);

            self::assertSame(
                [self::MUTED . "‹\e[0m" . self::MUTED . "›\e[0m", []],
                $this->render($labels, 1, 16),
            );
            self::assertSame(
                [self::MUTED . "›\e[0m", []],
                $this->render([$labels[1]], 0, 16),
                'with nothing to its left the guard draws the right mark alone',
            );
        });
    }

    public function testNaturalWidthCountsCellsAndSeparatorsWithoutMarks(): void
    {
        self::assertSame(0, FormTabs::naturalWidth([]));
        self::assertSame(44, FormTabs::naturalWidth(['Prompt', 'Generation params', 'Extra', 'Hires']));
    }

    public function testTheFormPaintsTheStripAndItsZonesFromTheSameFrame(): void
    {
        $this->inEnglish(function (): void {
            $form = MediaForm::open(MediaRequest::new());

            self::assertSame(
                self::FRAME_LEFT . self::ACTIVE . " Prompt \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Generation params \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Extra \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Hires. fix \e[0m" . self::MUTED . "›\e[0m       " . self::FRAME_RIGHT,
                $this->stripLine($form),
            );
            self::assertSame(self::FIRST_WINDOW_ZONES, $this->tabZones($form));
        });
    }

    public function testRightAndLeftSwitchTabsAndRepaintTheStrip(): void
    {
        $this->inEnglish(function (): void {
            $form = MediaForm::open(MediaRequest::new());

            [$right] = $form->update(new KeyMsg(KeyType::Right));
            self::assertNotNull($right);
            self::assertSame(1, $right->state()->section);
            self::assertSame(
                self::FRAME_LEFT . self::INACTIVE . " Prompt \e[0m" . self::DIVIDER
                    . self::ACTIVE . " Generation params \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Extra \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Hires. fix \e[0m" . self::MUTED . "›\e[0m       " . self::FRAME_RIGHT,
                $this->stripLine($right),
            );
            self::assertSame(self::FIRST_WINDOW_ZONES, $this->tabZones($right));

            [$left] = $form->update(new KeyMsg(KeyType::Left));
            self::assertNotNull($left);
            self::assertSame(10, $left->state()->section, 'Left from the first tab wraps to the last');
            self::assertSame(
                self::FRAME_LEFT . self::MUTED . "‹\e[0m" . self::INACTIVE . " Scripts \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Override settings \e[0m" . self::DIVIDER
                    . self::INACTIVE . " Presets \e[0m" . self::DIVIDER
                    . self::ACTIVE . " Output \e[0m        " . self::FRAME_RIGHT,
                $this->stripLine($left),
            );
            self::assertSame(
                [[1, 3, 12, 'media:tab:7'], [1, 13, 32, 'media:tab:8'], [1, 33, 42, 'media:tab:9'], [1, 43, 51, 'media:tab:10']],
                $this->tabZones($left),
            );
        });
    }

    public function testEverySectionJumpKeyMovesTheActiveTab(): void
    {
        $this->inEnglish(function (): void {
            $form = MediaForm::open(MediaRequest::new());

            $keys = [
                [new KeyMsg(KeyType::Right), 1],
                [new KeyMsg(KeyType::Left), 10],
                [new KeyMsg(KeyType::Tab), 1],
                [new KeyMsg(KeyType::Tab, shift: true), 10],
                [new KeyMsg(KeyType::PageDown), 1],
                [new KeyMsg(KeyType::PageUp), 10],
                [new KeyMsg(KeyType::Char, rune: 'l'), 1],
                [new KeyMsg(KeyType::Char, rune: 'h'), 10],
            ];
            foreach ($keys as $n => [$key, $section]) {
                [$moved] = $form->update($key);
                self::assertNotNull($moved);
                self::assertSame($section, $moved->state()->section, "key #{$n}");
                self::assertContains(
                    MediaForm::TAB_ZONE . $section,
                    array_column($this->tabZones($moved), 3),
                    "key #{$n}: the active tab keeps a hit zone",
                );
            }

            [$down] = $form->update(new KeyMsg(KeyType::Down));
            self::assertNotNull($down);
            self::assertSame(0, $down->state()->section, 'Down stays row navigation');
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
            static fn (): array => FormTabs::render($labels, $active, $width, Theme::dark()),
        );
    }

    private function inEnglish(callable $fn): void
    {
        Lang::inLocale('en', static fn () => Style::withDefaultProfile(null, $fn));
    }

    private function stripLine(MediaForm $form): string
    {
        return explode("\n", $form->view(Theme::dark(), 60, 24))[1];
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: string}> */
    private function tabZones(MediaForm $form): array
    {
        return array_values(array_filter(
            $form->zones(Theme::dark(), 60, 24),
            static fn (array $zone): bool => $zone[0] === 1,
        ));
    }
}
