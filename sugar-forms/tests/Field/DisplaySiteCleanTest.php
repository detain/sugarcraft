<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Forms\Field\Color;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Date;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\Theme;
use PHPUnit\Framework\TestCase;

/**
 * SF-21 (sugar-forms-023/-073): HasDynamicLabels closures are DESIGNED to
 * echo program data, so every field's title/description display site must
 * run RenderSafe::clean — titles through the themedTitle choke point
 * (clean first, style last), descriptions at their emission — and the
 * ItemList filter echo / status parts no less. This file is the pin both
 * ways: a functional sweep over every shipped field class and a source
 * scan so the family cannot re-drift around the seam.
 */
final class DisplaySiteCleanTest extends TestCase
{
    /** OSC payload + CR + BEL — none of which a legitimate theme emits. */
    private const PAYLOAD = "t\x1b]0;evil\x07\r\x07ail";

    /** @return array<string,array{0:object}> */
    public static function fieldProvider(): array
    {
        return [
            'Input'       => [Input::new('k')],
            'Text'        => [Text::new('k')],
            'NumberField' => [NumberField::new('k')],
            'Select'      => [Select::new('k')],
            'MultiSelect' => [MultiSelect::new('k')],
            'RadioPills'  => [RadioPills::new('k', 'one', 'two')],
            'Slider'      => [Slider::new('k')],
            'Confirm'     => [Confirm::new('k', false)],
            'Note'        => [Note::new('k')],
            'Date'        => [Date::new('k')],
            'Color'       => [Color::new('k')],
            'FilePicker'  => [FilePicker::new('k', sys_get_temp_dir())],
        ];
    }

    /**
     * @param object $field
     * @dataProvider fieldProvider
     */
    public function testEveryFieldRendersHostileDynamicLabelsClean($field): void
    {
        $hostile = $field
            ->withTitleFunc(static fn (): string => self::PAYLOAD)
            ->withDescriptionFunc(static fn (): string => self::PAYLOAD);

        foreach ([null, Theme::ansi()] as $theme) {
            $rendered = $field->view(true, $theme);
            $this->assertStringNotContainsString("\x1b]", $rendered, 'no OSC survives on screen');
            $this->assertStringNotContainsString("\r", $rendered, 'no CR survives on screen');
            $this->assertStringNotContainsString("\x07", $rendered, 'no BEL survives on screen');
        }
    }

    /**
     * The titles still VISIBLE after cleaning — proving the sweep runs the
     * cleaner at display rather than blanking labels wholesale.
     *
     * @param object $field
     * @dataProvider fieldProvider
     */
    public function testCleanedLabelsStillPaintTheirText($field): void
    {
        $hostile = $field
            ->withTitleFunc(static fn (): string => self::PAYLOAD)
            ->withDescriptionFunc(static fn (): string => self::PAYLOAD);

        $rendered = $hostile->view(true, null);
        $this->assertStringContainsString('evil', $rendered);
        $this->assertStringContainsString('t', $rendered);
    }

    /**
     * Defense-in-depth pin: resolveTitle() already sweeps upstream, so the
     * functional matrix cannot see the themedTitle seam's own clean being
     * removed. Reach through to the choke point directly — with a DIRTY
     * string (one that never passed the door) — and prove it cannot paint
     * hostile bytes regardless of theme state.
     */
    public function testThemedTitleChokePointCleansEvenDirtyInput(): void
    {
        $field = Input::new('k');
        $seam = new \ReflectionMethod($field, 'themedTitle');
        $seam->setAccessible(true);

        foreach ([null, Theme::ansi()] as $theme) {
            $painted = $seam->invoke($field, self::PAYLOAD, $theme);
            $this->assertStringNotContainsString("\x1b]", $painted);
            $this->assertStringNotContainsString("\x07", $painted);
            $this->assertStringNotContainsString("\r", $painted);
        }
    }

    public function testSourceScanForbidsRawDescriptionEmissionInFieldViews(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Field/*.php') ?: [] as $file) {
            $src = file_get_contents($file);
            $this->assertIsString($src, $file);
            $this->assertStringNotContainsString(
                '$lines[] = $desc;',
                (string) $src,
                basename($file) . ' emits a description without RenderSafe::clean'
            );
            $this->assertStringNotContainsString(
                '$parts[] = $desc;',
                (string) $src,
                basename($file) . ' emits a description without RenderSafe::clean'
            );
            $this->assertStringNotContainsString(
                '$lines[] = $title;',
                (string) $src,
                basename($file) . ' emits a title without the themedTitle choke point'
            );
        }
    }

    public function testFilterEchoAndStatusPartsAreCleaned(): void
    {
        $hostile = "\x1b]0;x\x07rm";
        $poison = function (ItemList $l) use ($hostile): ItemList {
            return (function () use ($hostile): self {
                return $this->mutate(filtering: true, filterText: $hostile);
            })->call($l);
        };

        // Width-0: no clip pass exists to incidentally rescue the echo —
        // it must be clean because the render site IS clean.
        $bare = $poison(ItemList::new([], 0, 5)->withTitle('')->withShowStatusBar(false));
        $bareView = $bare->view();
        $this->assertStringContainsString('/]0;xrm', $bareView, 'the echo survives as inert text (introducers disarmed)');
        $this->assertStringNotContainsString("\x1b]", $bareView, 'filter echo is clean unclipped');
        $this->assertStringNotContainsString("\x07", $bareView, 'no BEL rides the echoed filter');

        // Status: the quoted filter part and the host message part.
        $status = $poison(ItemList::new([
            new class implements \SugarCraft\Forms\ItemList\Item {
                public function title(): string { return 'a'; }
                public function description(): string { return ''; }
                public function filterValue(): string { return 'a'; }
            },
        ], 0, 5)->withTitle(''))->newStatusMessage('done ' . $hostile);
        $composed = $status->status();
        $this->assertStringNotContainsString("\x1b]", $composed);
        $this->assertStringNotContainsString("\x07", $composed);
        $this->assertStringContainsString('done ]0;xrm', $composed, 'status text survives as inert text');
    }
}
