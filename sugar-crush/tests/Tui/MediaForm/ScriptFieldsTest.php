<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\ScriptFields;
use SugarCraft\Crush\Tui\MediaForm\Sections\ScriptsSection;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Crush\Theme;

/**
 * The W3.6 scripts projection (plan §4.4/§4.5/§5.1/§5.2). The fixture
 * payload is DERIVED FROM THE PLAN — the A1111 clone is not on this box, so
 * tests/fixtures/sd/script-info.json hand-builds the §4.4 descriptor shape
 * (two selectable scripts, two alwayson, one of them zero-arg) rather than
 * recording a live server. JSON carries no comments, so the provenance
 * lives here.
 *
 * Pins: descriptor-to-widget type and count parity, the documented
 * `choices`-beats-bounds priority, the off-lattice no-mutilation fall to
 * text, the never-coexist law for the two selectable scripts, alwayson keys
 * equal to the EXACT fetched titles (422 avoidance), the header-only zero-
 * arg script, and the fail-open single note row when nothing was
 * discovered.
 */
final class ScriptFieldsTest extends TestCase
{
    private const SCRIPTS_GOLDEN_DIR = __DIR__ . '/golden';

    protected function setUp(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        ChoiceFeed::resetForTesting();
    }

    // ---- projection ladder (§4.4 machine keys) -----------------------------------

    public function testEveryFixtureArgumentProjectsOntoItsDeclaredWidgetKind(): void
    {
        $descriptors = ScriptFields::normalize($this->scriptInfoFixture());

        [$upscale, $xy, $altTest, $legacy] = $descriptors;
        $this->assertCount(4, $descriptors);
        $this->assertSame(ScriptFields::KIND_SLIDER, ScriptFields::kind($upscale['args'][0]), '1..4/1 int lattice rides a Slider');
        $this->assertSame(ScriptFields::KIND_SLIDER, ScriptFields::kind($upscale['args'][1]), '0.05..1.0/0.05 carries 0.35 on-lattice');
        $this->assertSame(ScriptFields::KIND_SELECT, ScriptFields::kind($xy['args'][0]));
        $this->assertSame(ScriptFields::KIND_INPUT, ScriptFields::kind($xy['args'][1]), 'a comma list is text');
        $this->assertSame(ScriptFields::KIND_SELECT, ScriptFields::kind($xy['args'][2]));
        $this->assertSame(ScriptFields::KIND_INPUT, ScriptFields::kind($altTest['args'][0]), 'bool rides a text Input seeded True/False');
        $this->assertSame(ScriptFields::KIND_SLIDER, ScriptFields::kind($altTest['args'][1]));
        $this->assertSame([], $legacy['args'], 'the zero-arg script keeps an empty argument list');
    }

    public function testChoicesBeatNumericBoundsWhenBothArePresented(): void
    {
        $arg = [
            'label' => 'Both',
            'value' => 2,
            'minimum' => 1.0,
            'maximum' => 3.0,
            'step' => 1.0,
            'choices' => ['1', '2', '3'],
        ];

        $this->assertSame(ScriptFields::KIND_SELECT, ScriptFields::kind($arg), 'documented priority: an enum means pick-one');
        $this->assertInstanceOf(Select::class, ScriptFields::field('k', $arg, 'Both'));
    }

    public function testAnUnboundedIntegerFallsToASteppedNumberField(): void
    {
        $arg = ['label' => 'Iterations', 'value' => 12, 'minimum' => null, 'maximum' => null, 'step' => null, 'choices' => []];

        $this->assertSame(ScriptFields::KIND_NUMBER, ScriptFields::kind($arg));
        $field = ScriptFields::field('k', $arg, 'Iterations');
        $this->assertInstanceOf(NumberField::class, $field);
        $this->assertSame(12, $field->value());

        [$min, $max] = $this->numberSpan($field);
        $this->assertLessThanOrEqual(-2_000_000_000, $min, 'the negative half-span must not invent a low ceiling');
        $this->assertGreaterThanOrEqual(2_000_000_000, $max);
    }

    public function testAnUnboundedFloatFallsToTextRatherThanAnInventedLattice(): void
    {
        $arg = ['label' => 'Loose', 'value' => 0.35, 'minimum' => null, 'maximum' => null, 'step' => null, 'choices' => []];

        $this->assertSame(ScriptFields::KIND_INPUT, ScriptFields::kind($arg), 'no bounds, no lattice, no snap');
        $field = ScriptFields::field('k', $arg, 'Loose');
        $this->assertInstanceOf(Input::class, $field);
        $this->assertSame('0.35', $field->value());
        $this->assertSame(0.35, ScriptFields::wireValue($field, $arg), 'text round-trips back to the declared float');
    }

    public function testAnOffLatticeDefaultEscapesToTextAndSurvivesUntouched(): void
    {
        $arg = ['label' => 'Odd', 'value' => 0.35, 'minimum' => 0.0, 'maximum' => 1.0, 'step' => 0.3, 'choices' => []];

        $this->assertSame(ScriptFields::KIND_INPUT, ScriptFields::kind($arg), '0.35 is not on the 0.0..1.0/0.3 grid — a Slider would mutilate it');
        $field = ScriptFields::field('k', $arg, 'Odd');
        $this->assertSame(0.35, ScriptFields::wireValue($field, $arg));
        $edited = $field->withValue('0.42');
        $this->assertSame(0.42, ScriptFields::wireValue($edited, $arg), 'a free edit is never snapped to the server grid');
    }

    public function testAnInt64TopDeclaredMaximumLandsOnASafeCeiling(): void
    {
        // R6-M1 caller law: a scraped descriptor may declare the int64
        // ceiling itself. The (int)-cast of that float is UB and the -1..
        // PHP_INT_MAX span is the shape NumberField's ctor now refuses —
        // ScriptFields must land it on the widest representable window.
        $arg = ['label' => 'Counter', 'value' => 7, 'minimum' => -1.0, 'maximum' => 9.3e18, 'step' => null, 'choices' => []];

        $this->assertSame(ScriptFields::KIND_NUMBER, ScriptFields::kind($arg), 'no step, no lattice — the number widget owns it');
        $field = ScriptFields::field('k', $arg, 'Counter');
        $this->assertInstanceOf(NumberField::class, $field);
        $this->assertSame(-1, $field->min);
        $this->assertSame(PHP_INT_MAX - 1, $field->max, 'ceiling = PHP_INT_MAX + min — the FieldSpec discipline, derived');
        $this->assertSame(7, $field->value(), 'the default rides the window byte-exact');
        $this->assertSame(7, ScriptFields::wireValue($field, $arg));
        $this->assertSame(PHP_INT_MAX - 1, $field->withValue(PHP_INT_MAX)->value(), 'an arrow past the top clamps onto the capped ceiling');
    }

    public function testBoundsBeyondInt64OnBothSidesCollapseToTheWidestRepresentableWindow(): void
    {
        $arg = ['label' => 'Chaos', 'value' => 7, 'minimum' => -1e30, 'maximum' => 1e30, 'step' => null, 'choices' => []];

        $this->assertSame(ScriptFields::KIND_NUMBER, ScriptFields::kind($arg));
        $field = ScriptFields::field('k', $arg, 'Chaos'); // span_overflow throw pre-cure
        $this->assertInstanceOf(NumberField::class, $field);
        $this->assertSame(PHP_INT_MIN, $field->min, 'each bound lands on the range edge before the span repair');
        $this->assertSame(-1, $field->max, 'PHP_INT_MAX + PHP_INT_MIN keeps the span exactly representable');
        $this->assertSame(-1, $field->value(), 'an out-of-window default clamps to the ceiling, honestly');
    }

    // ---- section layout and the never-coexist law ---------------------------------

    public function testTheSelectedScriptRendersExactlyItsArgumentRows(): void
    {
        $section = $this->scriptsSection(['script_name' => 'SD Upscale']);
        $rows = $section->rows();

        $this->assertCount(2, $this->fixtureArgRows($section, 'script_arg:'), 'SD Upscale declares two args');
        $this->assertInstanceOf(Slider::class, $this->rowField($section, 'script_arg:0'));
        $this->assertInstanceOf(Slider::class, $this->rowField($section, 'script_arg:1'));
        $this->assertStringContainsString('Scale factor', $rows[$this->rowIndexOf($section, 'script_arg:0')]);
        $this->assertContains(Lang::t(GenFormKeys::SCRIPTS_IMG2IMG_NOTE), $rows, 'an img2img descriptor puts the mode note on screen');
    }

    public function testSwitchingTheDropdownRebuildsArgsSoTwoScriptsNeverCoexist(): void
    {
        $section = $this->scriptsSection(['script_name' => 'SD Upscale', 'script_args' => [3, 0.55]]);
        $select = $section->fieldAt($this->rowIndexOf($section, ScriptsSection::ROW_SELECT));
        $this->assertInstanceOf(Select::class, $select);

        $switched = $section->withField($select->withSelected('X/Y plot'));
        $args = $this->fixtureArgRows($switched, 'script_arg:');
        $this->assertCount(3, $args, 'the new script declares three args — the outgoing two are gone');
        $this->assertInstanceOf(Select::class, $args[0]);
        $this->assertInstanceOf(Input::class, $args[1]);

        $wire = $switched->collect(MediaRequest::new());
        $this->assertSame('X/Y plot', $wire->scriptName());
        $this->assertSame(['Nothing', '1,2,3', 'Nothing'], $wire->scriptArgs(), 'values are the NEW script defaults, no stale carryover');
    }

    public function testNoneSelectedEmitsNoScriptPairOnAFreshDraft(): void
    {
        $wire = $this->scriptsSection([])->collect(MediaRequest::new());

        $this->assertNull($wire->scriptName());
        $this->assertNull($wire->scriptArgs());
        $this->assertFalse($wire->isSet('script_name'));
        $this->assertFalse($wire->isSet('script_args'));
    }

    public function testStaleScriptKeysAreClearedWhenTheDropdownGoesBackToNone(): void
    {
        $draft = MediaRequest::fromArray(['script_name' => 'SD Upscale', 'script_args' => [2, 0.35]]);
        $section = $this->scriptsSection(['script_name' => 'SD Upscale', 'script_args' => [2, 0.35]]);
        $select = $section->fieldAt($this->rowIndexOf($section, ScriptsSection::ROW_SELECT));
        $this->assertInstanceOf(Select::class, $select);

        $wire = $section->withField($select->withSelected(Lang::t(GenFormKeys::SCRIPTS_NONE)))->collect($draft);

        $this->assertTrue($wire->isSet('script_name'), 'a cleared key rides as null, not a stale value');
        $this->assertNull($wire->scriptName());
        $this->assertNull($wire->scriptArgs());
    }

    // ---- alwayson serialization (§4.5, INCLUDE-ALL ruling) ------------------------

    public function testAlwaysonKeysAreTheExactFetchedTitlesInServerOrder(): void
    {
        $wire = $this->scriptsSection([])->collect(MediaRequest::new());
        $alwayson = $wire->alwaysonScripts();

        $this->assertIsArray($alwayson);
        $this->assertSame(
            ['img2img alternative test', 'outdated auto backward-compat'],
            array_keys($alwayson),
            'titles are the same strings the widgets were built from — a hand-typed second copy is a 422',
        );
        $this->assertSame(
            [
                'img2img alternative test' => ['args' => [false, 0.3]],
                'outdated auto backward-compat' => ['args' => []],
            ],
            $alwayson,
            'collapsed accordions still post their current values (hidden inputs always ride)',
        );
    }

    public function testTheZeroArgScriptIsHeaderOnly(): void
    {
        $section = $this->scriptsSection([]);

        $this->assertInstanceOf(Confirm::class, $this->rowField($section, 'alwayson:3'));
        $this->assertStringContainsString('outdated auto backward-compat', $section->rows()[$this->rowIndexOf($section, 'alwayson:3')]);
        $this->assertSame([], $this->fixtureArgRows($section, 'alwayson:3:arg:'), 'no argument rows exist, open or closed');

        $opened = $section->withField(Confirm::new('alwayson:3', true));
        $this->assertSame([], $this->fixtureArgRows($opened, 'alwayson:3:arg:'));
    }

    public function testNoScriptTitleIsHandTypedIntoTheProjectionSource(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Tui/MediaForm/Sections/ScriptsSection.php')
            . file_get_contents(__DIR__ . '/../../../src/Tui/MediaForm/ScriptFields.php');

        foreach (array_column(ScriptFields::normalize($this->scriptInfoFixture()), 'name') as $title) {
            $this->assertStringNotContainsString($title, $source, 'every title must flow from the fetch, never a literal copy');
        }
    }

    public function testAnEditedAlwaysonValueRidesTheWireAfterExpanding(): void
    {
        $section = $this->scriptsSection([]);
        $opened = $section->withField(Confirm::new('alwayson:2', true));
        $bool = $this->rowField($opened, 'alwayson:2:arg:0');
        $this->assertInstanceOf(Input::class, $bool);

        $wire = $opened
            ->withField($bool->withValue('True'))
            ->collect(MediaRequest::new());

        $this->assertSame([true, 0.3], $wire->alwaysonScripts()['img2img alternative test']['args'], "'True' maps back to the declared bool");
    }

    // ---- fail-open undiscovered tab ------------------------------------------------

    public function testUndiscoveredScriptsCollapseToOneNoteRowAndPassTheDraftThrough(): void
    {
        $draft = MediaRequest::new();
        $section = ScriptsSection::forDraft($draft, null);

        $this->assertSame([Lang::t(GenFormKeys::SCRIPTS_NO_BACKEND)], $section->rows());
        $this->assertNull($section->fieldAt(0));
        $this->assertSame($draft, $section->collect($draft), 'no fetch, no writes — fail-open');
        $this->assertSame('', $section->valueAt(0), 'the note rides the label column only');
    }

    public function testTheScriptsTabRidesTheOpenFormStrip(): void
    {
        $frame = Ansi::strip(MediaForm::open()->view(Theme::default(), 100, 40));

        $this->assertStringContainsString(Lang::t(GenFormKeys::SECTION_SCRIPTS), $frame);
    }

    // ---- frame golden ---------------------------------------------------------------

    public function testOneAlwaysonAccordionOpenRendersTheGoldenFrame(): void
    {
        $section = $this->scriptsSection([])->withField(Confirm::new('alwayson:2', true));
        $frame = Ansi::strip(
            MediaForm::fromSections([$section], MediaRequest::new())
                ->click(MediaForm::TAB_ZONE . '0')
                ->view(Theme::default(), 80, 24),
        );

        $this->assertScriptsGolden('scripts-alwayson-open-80x24.txt', $frame);
        $this->assertStringContainsString('Original first pass', $frame);
        $this->assertStringContainsString('Threshold', $frame);
    }

    // ---- helpers (uniquely named for the drift guard) --------------------------------

    /**
     * @param array<string, mixed> $wireKeys
     */
    private function scriptsSection(array $wireKeys): ScriptsSection
    {
        return ScriptsSection::forDraft(
            $wireKeys === [] ? MediaRequest::new() : MediaRequest::fromArray($wireKeys),
            $this->scriptInfoFixture(),
        );
    }

    /**
     * @return list<mixed>
     */
    private function scriptInfoFixture(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/sd/script-info.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function rowField(ScriptsSection $section, string $key): ?Field
    {
        return $section->fieldAt($this->rowIndexOf($section, $key));
    }

    /**
     * @return array<string, \SugarCraft\Forms\Field>
     */
    private function fixtureArgRows(ScriptsSection $section, string $prefix): array
    {
        $found = [];
        foreach ($section->rows() as $row => $label) {
            $field = $section->fieldAt($row);
            if ($field !== null && str_starts_with($field->key(), $prefix)) {
                $found[] = $field;
            }
        }

        return $found;
    }

    /** Positional row of a FIELD-bearing key (notes are found by label). */
    private function rowIndexOf(ScriptsSection $section, string $key): int
    {
        for ($row = 0; $row < count($section->rows()); $row++) {
            if ($section->fieldAt($row)?->key() === $key) {
                return $row;
            }
        }

        $this->fail("row {$key} is not on screen");
    }

    /**
     * @return array{0: int|float, 1: int|float}
     */
    private function numberSpan(NumberField $field): array
    {
        $below = $field->withValue(-9_999_999_999);
        $above = $field->withValue(9_999_999_999);

        return [$below->value(), $above->value()];
    }

    private function assertScriptsGolden(string $name, string $actual): void
    {
        $path = self::SCRIPTS_GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!is_dir(self::SCRIPTS_GOLDEN_DIR)) {
                mkdir(self::SCRIPTS_GOLDEN_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}
