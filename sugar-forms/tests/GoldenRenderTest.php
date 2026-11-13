<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
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
use SugarCraft\Forms\Form;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\Scrollbar\Scrollbar;
use SugarCraft\Forms\Scrollbar\ScrollbarState;
use SugarCraft\Forms\Theme;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output (SF-55).
 *
 * The two legacy fixtures under `tests/fixtures/` (Confirm field and the
 * Confirm+Text form composite) predate this wave; SF-55 grows the genre
 * from two fixtures deep to one golden per field class plus the visual-risk
 * surfaces the finding names: password/echo modes, the Select filter pan,
 * the Date/Color/Slider pickers, ItemList windowing, and the Scrollbar
 * thumb. The last code wave of the SF-series (SF-33 chrome keys, SF-36
 * masks, SF-27 windowing math, SF-40 history draft, SF-24 validation rows)
 * moved behavior these fixtures now pin at final bytes — this step CREATES
 * the dataset, so zero re-pins are owed; any byte move in the two legacy
 * fixtures would be a regression to investigate, not to accept.
 *
 * REGENERATION (house policy, sugar-testing Assertions):
 *   UPDATE_GOLDENS=1 vendor/bin/phpunit --filter GoldenRenderTest
 * creates missing fixtures one-way — an existing golden is NEVER rewritten.
 * To re-baseline on purpose: delete the specific `.ansi` file, rerun with
 * the env var set, then review the git diff of the fixture before commit
 * (same dance as every `*.golden` site in the fleet).
 *
 * Determinism rules observed by every fixture below: fixed literal content
 * only (no clocks, no randomness, no locale probing); the FilePicker arm
 * renders against a temporary directory whose host-specific path is
 * replaced with a fixed `/sugar-forms-goldens-picker` token before the
 * comparison, so the fixture bytes are identical on every machine; the
 * themed arm pins one Dracula pass so in-body theme bytes are covered.
 */
final class GoldenRenderTest extends TestCase
{
    /** Normalization token for the FilePicker fixture's temporary cwd. */
    private const PICKER_PATH_TOKEN = '/sugar-forms-goldens-picker';

    private string $fixturesDir;
    private string $goldenDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->goldenDir = __DIR__ . '/_goldens';
    }

    public function testConfirmFieldRendersWithAnsi(): void
    {
        $f = Confirm::new('agree')
            ->withTitle('Do you agree?')
            ->withDescription('Please confirm your choice')
            ->withDefault(true);

        $output = $f->view();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Do you agree?', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/confirm-field.golden',
            $output,
        );
    }

    public function testFormWithConfirmAndTextInputRendersWithAnsi(): void
    {
        $form = Form::new(
            Confirm::new('agree')->withTitle('Terms of Service'),
            Text::new('name')->withTitle('Your Name'),
        );

        $output = $form->view();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Terms of Service', $output);
        $this->assertStringContainsString('Your Name', $output);

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/form-confirm-text.golden',
            $output,
        );
    }

    // -----------------------------------------------------------------
    // SF-55 dataset — one golden per field class, fixed content.
    // -----------------------------------------------------------------

    public function testInputField(): void
    {
        $f = Input::new('name')
            ->withTitle('Your name')
            ->withDescription('Shown publicly')
            ->withValue('ada lovelace');

        self::assertSame('ada lovelace', $f->value());
        $this->assertGolden('input', $f->view());
    }

    public function testInputPasswordField(): void
    {
        $f = Input::new('secret')
            ->withTitle('Password')
            ->withValue('hunter2')
            ->withPassword(true, '*');

        $this->assertGolden('input-password', $f->view());
        $this->assertStringNotContainsString('hunter2', $f->view());
    }

    public function testInputPatternMaskedField(): void
    {
        // SF-36 semantics: matched class cells echo masked, others plain.
        $f = Input::new('pin')
            ->withTitle('Pin')
            ->withValue('a1b2')
            ->withPasswordMask('[0-9]', '#');

        $this->assertGolden('input-mask', $f->view());
    }

    public function testTextField(): void
    {
        $f = Text::new('bio')
            ->withTitle('Biography')
            ->withValue('short prose');

        $this->assertGolden('text', $f->view());
    }

    public function testNumberFieldPicker(): void
    {
        $f = NumberField::new('qty', 0, 999, 1, 42)
            ->withTitle('Quantity');

        $this->assertGolden('numberfield', $f->view());
    }

    public function testSelectCollapsedAndExpandedPan(): void
    {
        $f = Select::new('fruit', 'Apple', 'Banana', 'Cherry')
            ->withTitle('Fruit')
            ->withSelected('Banana');

        $this->assertGolden('select', $f->view());

        // The filter pan (open state): '/' opens filtering, 'an' narrows.
        [$f,] = $f->focus();
        [$f,] = $f->update(new KeyMsg(KeyType::Char, '/'));
        [$f,] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        [$f,] = $f->update(new KeyMsg(KeyType::Char, 'n'));

        $this->assertGolden('select-filter', $f->view());
    }

    public function testSelectDraculaArm(): void
    {
        // One themed pass pins in-body theme bytes (header note).
        $f = Select::new('fruit', 'Apple', 'Banana', 'Cherry')
            ->withTitle('Fruit')
            ->withSelected('Banana');
        [$f,] = $f->focus();

        $this->assertGolden('select-dracula', $f->view(true, Theme::dracula()));
    }

    public function testMultiSelectField(): void
    {
        $f = MultiSelect::new('toppings', 'Cheese', 'Ham', 'Pineapple')
            ->withTitle('Toppings')
            ->withValue(['Cheese', 'Pineapple']);

        $this->assertGolden('multiselect', $f->view());
    }

    public function testDatePicker(): void
    {
        // Fixed calendar: 2026-05, cursor on the 17th (never 'today' — the
        // grid would drift daily otherwise).
        $f = Date::new('when', '2026-05-17')
            ->withTitle('Pick a date');

        $this->assertGolden('date', $f->view());
    }

    public function testSliderPicker(): void
    {
        $f = Slider::new('volume', 0, 100, 1, 30)
            ->withTitle('Volume');

        $this->assertGolden('slider', $f->view());
    }

    public function testColorPicker(): void
    {
        // Default 256-color cells (truecolor=false) keep the fixture to
        // palette indices rather than host-dependent RGB.
        $f = Color::new('accent', '#3f7d00')
            ->withTitle('Accent color');

        $this->assertGolden('color', $f->view());
    }

    public function testRadioPillsField(): void
    {
        $f = RadioPills::new('size', 'Small', 'Medium', 'Large')
            ->withTitle('Size')
            ->withValue('Medium');

        $this->assertGolden('radiopills', $f->view());
    }

    public function testFilePickerField(): void
    {
        $dir = $this->makePickerFixture();
        try {
            $f = FilePicker::new('file', $dir)
                ->withTitle('Choose a file');

            $bytes = str_replace($dir, self::PICKER_PATH_TOKEN, $f->view());
            $this->assertGolden('filepicker', $bytes);
        } finally {
            $this->removePickerFixture($dir);
        }
    }

    public function testNoteField(): void
    {
        $f = Note::new('intro')
            ->withTitle('Welcome')
            ->withDescription('Read me first')
            ->withNext(true);

        $this->assertGolden('note', $f->view());
    }

    // -----------------------------------------------------------------
    // SF-55 dataset — widget families the finding names.
    // -----------------------------------------------------------------

    public function testItemListWindowing(): void
    {
        // Small height + two-line items pin the SF-27 lines math: six
        // items (12 rows with descriptions) windowed to three, cursor
        // walked past the fold so the offset arrows render.
        $items = [];
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'] as $i => $title) {
            $items[] = new GoldenDescribedItem($title, 'detail line ' . ($i + 1));
        }
        $list = ItemList::new($items, 40, 3);
        [$list,] = $list->focus();
        for ($i = 0; $i < 4; $i++) {
            [$list,] = $list->update(new KeyMsg(KeyType::Down));
        }

        $this->assertGolden('itemlist-windowing', $list->view());
    }

    public function testScrollbarArms(): void
    {
        $bar = Scrollbar::vertical();
        $state = ScrollbarState::new(total: 20, position: 5, viewport: 10);

        $this->assertGolden('scrollbar-partial', $bar->view($state, 8));

        // Content that fits: pure track, no thumb travel.
        $fits = ScrollbarState::new(total: 4, position: 0, viewport: 10);
        $this->assertGolden('scrollbar-fits', $bar->view($fits, 8));

        $this->assertGolden('scrollbar-horizontal', Scrollbar::horizontal()->view($state, 12));
    }

    // -----------------------------------------------------------------

    private function assertGolden(string $name, string $bytes): void
    {
        $this->assertNotEmpty($bytes, "Golden surface '{$name}' rendered empty bytes.");
        Assertions::assertGoldenAnsi($this->goldenDir . '/' . $name . '.ansi', $bytes);
    }

    /**
     * Fixed-bytes content so rendered sizes are stable on every host.
     */
    private function makePickerFixture(): string
    {
        $dir = sys_get_temp_dir() . '/sugar-forms-goldens-picker-' . getmypid();
        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o755, true), "fixture dir {$dir}");
        }
        foreach ([
            'alpha.txt' => 'alpha',
            'beta.md' => 'beta',
            'gamma.log' => 'gamma',
        ] as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }
        if (!is_dir($dir . '/resources')) {
            mkdir($dir . '/resources');
        }

        return $dir;
    }

    private function removePickerFixture(string $dir): void
    {
        foreach (['alpha.txt', 'beta.md', 'gamma.log'] as $name) {
            @unlink($dir . '/' . $name);
        }
        @rmdir($dir . '/resources');
        @rmdir($dir);
    }
}

/**
 * Minimal two-line item for the ItemList windowing golden.
 */
final class GoldenDescribedItem implements Item
{
    public function __construct(
        private readonly string $rowTitle,
        private readonly string $rowDescription,
    ) {}

    public function title(): string { return $this->rowTitle; }

    public function description(): string { return $this->rowDescription; }

    public function filterValue(): string { return $this->rowTitle; }
}
