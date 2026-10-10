<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\MediaForm;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Crush\Tui\MediaForm\GenFormKeys;
use SugarCraft\Crush\Tui\MediaForm\MediaForm;
use SugarCraft\Crush\Tui\MediaForm\OverrideRows;
use SugarCraft\Crush\Tui\MediaForm\Sections\AdvancedSection;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

/**
 * The W3.8 typed override row editor (plan §4.2/§5.5). The options map is
 * hand-built here rather than a second JSON fixture: it stands in for the
 * lazily-fetched GET /sdapi/v1/options payload, and only its key/value TYPE
 * spread matters (int/float/bool/string entries exercise the inference).
 *
 * Pins: per-type parses and refusals, the bool trap where the STRING 'false'
 * (and '0') must yield false and never a truthy string, the json escape
 * hatch accepting {"a":[1]} while rejecting truncated JSON, out-of-span
 * values like uni_pc_order round-tripping UNTOUCHED (no local clamp — the
 * server validates), the empty editor emitting no keys at all, the loud
 * per-row error that skips serialization without dropping the text, and
 * row add/remove/reorder flow driving the wire dictionary's key order.
 */
final class OverrideRowsTest extends TestCase
{
    private const ADVANCED_GOLDEN_DIR = __DIR__ . '/golden';

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

    // ---- per-type parsing (§4.2) ------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     */
    public static function acceptedRowValues(): array
    {
        return [
            'whole int' => ['int', '50', 50],
            'negative int' => ['int', '-42', -42],
            'float from decimal' => ['float', '0.35', 0.35],
            'float from whole text' => ['float', '2', 2.0],
            'bool true word' => ['bool', 'true', true],
            'bool capitalised' => ['bool', 'True', true],
            'bool one' => ['bool', '1', true],
            'bool on' => ['bool', 'on', true],
            // The mutation trap: a naive (bool)$text would post TRUE here.
            'bool false word' => ['bool', 'false', false],
            'bool capital false' => ['bool', 'False', false],
            'bool zero' => ['bool', '0', false],
            'bool off' => ['bool', 'off', false],
            'string keeps anything' => ['string', 'not a number', 'not a number'],
            'string is never rewritten to numeric' => ['string', '50', '50'],
            'json object' => ['json', '{"a":[1]}', ['a' => [1]]],
            'json bare null' => ['json', 'null', null],
        ];
    }

    /**
     * @dataProvider acceptedRowValues
     */
    public function testEachTypeParsesItsAcceptedSpelling(string $type, string $text, mixed $expected): void
    {
        $this->assertSame($expected, OverrideRows::parse($type, $text));
        $this->assertNull(OverrideRows::errorText($type, $text));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function refusedRowValues(): array
    {
        return [
            'int refuses a decimal' => ['int', '5.5', 'not a whole number'],
            'int refuses words' => ['int', 'soon', 'not a whole number'],
            'int refuses empty' => ['int', '', 'not a whole number'],
            'float refuses words' => ['float', 'abc', 'not a number'],
            'bool refuses hedging' => ['bool', 'maybe', 'not a yes/no value'],
            'json refuses truncation' => ['json', '{"a":', 'not valid JSON'],
            'unknown type refuses itself' => ['nope', 'x', 'unknown override type nope'],
        ];
    }

    /**
     * @dataProvider refusedRowValues
     */
    public function testEachTypeRefusesItsRejectedSpellingLoudly(string $type, string $text, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        OverrideRows::parse($type, $text);
    }

    public function testAnEmptyValueIsIncompleteRatherThanBroken(): void
    {
        // A keyless/valueless row is still being typed: no cell error, but
        // toAssoc() contributes nothing for it (DTO unset law).
        $this->assertNull(OverrideRows::errorText('bool', ''));
        $this->assertNull(OverrideRows::errorText('json', ''));
        $this->assertSame([], OverrideRows::fromAssoc(['x' => 'v'])->withText(0, '')->toAssoc());
    }

    // ---- round-trip without local judgement (§5.5) ------------------------------

    public function testWireRoundTripKeepsEveryValueUntouched(): void
    {
        $settings = [
            'uni_pc_order' => 50,
            'sd_model_checkpoint' => 'v1.5',
            'sd_unet_precision' => 'full',
            'use_adaptive_noise' => true,
            'sigma_max' => 0.75,
        ];

        $roundTrip = OverrideRows::fromAssoc($settings)->toAssoc();

        $this->assertSame($settings, $roundTrip, 'fromAssoc/toAssoc is a fixed point, types included');
    }

    public function testAnOutOfSpanOverrideIsNeverClampedLocally(): void
    {
        // uni_pc_order truly lives in 1..50 on the server; the client ships
        // 99999 verbatim and lets the server say no.
        $rows = OverrideRows::empty()->appended()->withKey(0, 'uni_pc_order')->withType(0, 'int')->withText(0, '99999');

        $this->assertSame(['uni_pc_order' => 99999], $rows->toAssoc());
    }

    public function testInferenceAndStringifyCoverEveryWireShape(): void
    {
        $this->assertSame('int', OverrideRows::inferType(5));
        $this->assertSame('bool', OverrideRows::inferType(false));
        $this->assertSame('float', OverrideRows::inferType(0.5));
        $this->assertSame('string', OverrideRows::inferType('x'));
        $this->assertSame('json', OverrideRows::inferType(['a' => 1]));

        $this->assertSame('true', OverrideRows::stringify(true));
        $this->assertSame('false', OverrideRows::stringify(false));
        $this->assertSame('{"a":[1]}', OverrideRows::stringify(['a' => [1]]));

        $rows = OverrideRows::fromAssoc(['ui_tab' => ['hidden', 'panels']]);
        $this->assertSame('json', $rows->row(0)['type']);
        $this->assertSame(['ui_tab' => ['hidden', 'panels']], $rows->toAssoc());
    }

    public function testRowEditsAreImmutableAndEdgeMovesAreNoOps(): void
    {
        $base = OverrideRows::fromAssoc(['a' => 1, 'b' => 2]);

        $grown = $base->appended();
        $this->assertSame(2, $base->count(), 'appended() returns a new model');
        $this->assertSame(3, $grown->count());
        $this->assertSame(['key' => '', 'type' => 'string', 'text' => ''], $grown->row(2));

        $this->assertSame([['key' => 'a', 'type' => 'int', 'text' => '1']], $base->dropped(1)->rows());

        $edge = $base->moved(0, -1);
        $this->assertSame($base, $edge, 'shifting the first row up is a no-op');
        $this->assertSame($base, $base->moved(1, 1));
        $this->assertSame(['b', 'a'], array_keys($base->moved(0, 1)->toAssoc()));

        $this->expectException(\OutOfRangeException::class);
        $base->row(9);
    }

    public function testAnUnknownTypeIsRefusedAtTheModelDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OverrideRows::empty()->appended()->withType(0, 'colour');
    }

    // ---- section flow: add, infer, commit ----------------------------------------

    public function testAFreshEditorEmitsNoKeysAtAll(): void
    {
        $section = AdvancedSection::forDraft(MediaRequest::new(), $this->advancedOptionsMap());
        $draft = MediaRequest::new();

        $collected = $section->collect($draft);

        $this->assertSame($draft, $collected, 'empty rows and an unset draft: not even a null rides');
        $this->assertFalse($collected->isSet('override_settings'));
        $this->assertFalse($collected->isSet('override_settings_restore_afterwards'));
        $this->assertArrayNotHasKey('override_settings', $collected->toArray());
    }

    public function testATypedKnownKeyAdoptsTheOptionsTypeAndCurrentValue(): void
    {
        $section = $this->advancedSection([])->withField(Confirm::new(AdvancedSection::ROW_ADD, true));
        $this->assertSame('string', $this->advancedCellText($section, 'adv_type:0'), 'a fresh row starts as string');

        $section = $section->withField(Input::new('adv_key:0')->withValue('uni_pc_order'));

        $this->assertSame('int', $this->advancedCellText($section, 'adv_type:0'), 'the options map inferred int');
        $this->assertSame('50', $this->advancedCellText($section, 'adv_value:0'), 'the current server value seeds the text');

        $collected = $section->collect(MediaRequest::new());
        $this->assertSame(['uni_pc_order' => 50], $collected->overrideSettings());
        $this->assertTrue($collected->overrideSettingsRestoreAfterwards(), 'the restore companion defaults true');
    }

    public function testAKeyTheOptionsMapNeverHeardOfStillShips(): void
    {
        // Extension options are precisely the point (§5.5): completion is a
        // suggestion, never a gate — with or without a fetched map.
        $withMap = $this->advancedSection([])->withField(Confirm::new(AdvancedSection::ROW_ADD, true));
        $withMap = $withMap
            ->withField(Input::new('adv_key:0')->withValue('my_ext.secret'))
            ->withField(Input::new('adv_value:0')->withValue('42'));

        $this->assertSame('string', $this->advancedCellText($withMap, 'adv_type:0'), 'an unknown key keeps the human\'s type');
        $this->assertSame(['my_ext.secret' => '42'], $withMap->collect(MediaRequest::new())->overrideSettings());

        $withoutMap = AdvancedSection::forDraft(MediaRequest::new(), null)
            ->withField(Confirm::new(AdvancedSection::ROW_ADD, true))
            ->withField(Input::new('adv_key:0')->withValue('uni_pc_order'))
            ->withField(Input::new('adv_value:0')->withValue('30'));
        $this->assertSame('string', $this->advancedCellText($withoutMap, 'adv_type:0'), 'undiscovered options infer nothing');
        $this->assertSame(['uni_pc_order' => '30'], $withoutMap->collect(MediaRequest::new())->overrideSettings());
    }

    public function testKeyCompletionCandidatesComeFromTheOptionsMap(): void
    {
        $section = $this->advancedSection([]);
        $probe = $section->withField(Confirm::new(AdvancedSection::ROW_ADD, true));
        $keyRow = $this->advancedField($probe, 'adv_key:0');
        $this->assertInstanceOf(Input::class, $keyRow);

        $candidates = $this->advancedInputCandidates($keyRow);
        $this->assertContains('uni_pc_order', $candidates);
        $this->assertContains('sd_model_checkpoint', $candidates);

        $bare = AdvancedSection::forDraft(MediaRequest::new(), null)
            ->withField(Confirm::new(AdvancedSection::ROW_ADD, true));
        $this->assertSame([], $this->advancedInputCandidates($this->advancedField($bare, 'adv_key:0')), 'no map, no candidates');
    }

    public function testABrokenRowShoutsInTheCellAndStaysOutOfTheWire(): void
    {
        // Row 1 is declared int and left half-typed as 'soon' — exactly the
        // shape a human eyes while editing.
        $section = $this->advancedSection(['sd_model_checkpoint' => 'v1.5'])
            ->withField(Confirm::new(AdvancedSection::ROW_ADD, true))
            ->withField(Input::new('adv_key:1')->withValue('sigma_max'))
            ->withField(Select::new('adv_type:1')->withOptions(...OverrideRows::TYPES)->withSelected('int'))
            ->withField(Input::new('adv_value:1')->withValue('soon'));
        $this->assertSame('sigma_max', $this->advancedCellText($section, 'adv_key:1'));

        $this->assertStringContainsString('error: not a whole number', $this->advancedCellText($section, 'adv_value:1'));
        $this->assertSame(['sd_model_checkpoint' => 'v1.5'], $section->collect(MediaRequest::new())->overrideSettings());

        // Loud, never lost: fixing the text puts the row back on the wire.
        $fixed = $section->withField(Input::new('adv_value:1')->withValue('7'));
        $this->assertSame('7', $this->advancedCellText($fixed, 'adv_value:1'));
        $this->assertSame(['sd_model_checkpoint' => 'v1.5', 'sigma_max' => 7], $fixed->collect(MediaRequest::new())->overrideSettings());

        // Or the human can declare the type they meant: 'soon' is a fine string.
        $retyped = $section->withField(Select::new('adv_type:1')->withOptions(...OverrideRows::TYPES)->withSelected('string'));
        $this->assertSame('soon', $this->advancedCellText($retyped, 'adv_value:1'), 'the text never mutated');
        $this->assertSame(
            ['sd_model_checkpoint' => 'v1.5', 'sigma_max' => 'soon'],
            $retyped->collect(MediaRequest::new())->overrideSettings(),
        );
    }

    public function testReorderAndRemoveSteerTheWireDictionary(): void
    {
        $section = $this->advancedSection(['alpha' => 1, 'beta' => 2]);

        $moved = $section->withField(Confirm::new('adv_down:0', true));
        $this->assertSame(['beta', 'alpha'], array_keys($moved->collect(MediaRequest::new())->overrideSettings()));
        $this->assertSame(['alpha', 'beta'], array_keys($moved->withField(Confirm::new('adv_up:1', true))->collect(MediaRequest::new())->overrideSettings()));

        $dropped = $section->withField(Confirm::new('adv_remove:0', true));
        $this->assertSame(['beta' => 2], $dropped->collect(MediaRequest::new())->overrideSettings());
        $this->assertNotNull($this->advancedFieldOrNull($dropped, 'adv_value:0'), 'the surviving row keeps its triple');
        $this->assertNull($this->advancedFieldOrNull($dropped, 'adv_value:1'), 'the dropped row leaves no widget behind');
        $this->assertNull($this->advancedFieldOrNull($dropped, 'adv_up:0'), 'the lone row needs no move buttons');
        $this->assertNull($this->advancedFieldOrNull($dropped, 'adv_down:0'));
        $this->assertNotNull($this->advancedFieldOrNull($dropped, 'adv_remove:0'));
    }

    public function testTheRestoreCompanionRidesOnlyWhenOverridesShip(): void
    {
        $loaded = $this->advancedSection(['uni_pc_order' => 9]);
        $off = $loaded->withField(Confirm::new(AdvancedSection::ROW_RESTORE, false));
        $shipped = $off->collect(MediaRequest::new());
        $this->assertFalse($shipped->overrideSettingsRestoreAfterwards(), 'the flipped flag is what ships');

        // Deleting every row clears the stale pair AND its companion.
        $emptied = $loaded->withField(Confirm::new('adv_remove:0', true));
        $cleared = $emptied->collect($shipped);
        $this->assertTrue($cleared->isSet('override_settings'), 'the stale value is cleared, not preserved');
        $this->assertNull($cleared->overrideSettings());
        $this->assertNull($cleared->overrideSettingsRestoreAfterwards());
    }

    public function testTheEditorReadsBackTheDraftsExistingOverrides(): void
    {
        $draft = MediaRequest::fromArray([
            'override_settings' => ['uni_pc_order' => 12, 'use_adaptive_noise' => false],
        ]);
        $section = AdvancedSection::forDraft($draft, $this->advancedOptionsMap());

        $this->assertSame('uni_pc_order', $this->advancedCellText($section, 'adv_key:0'));
        $this->assertSame('int', $this->advancedCellText($section, 'adv_type:0'));
        $this->assertSame('12', $this->advancedCellText($section, 'adv_value:0'));
        $this->assertSame('bool', $this->advancedCellText($section, 'adv_type:1'));
        $this->assertSame('false', $this->advancedCellText($section, 'adv_value:1'));
        $this->assertSame(
            ['uni_pc_order' => 12, 'use_adaptive_noise' => false],
            $section->collect($draft)->overrideSettings(),
        );
    }

    // ---- registration + frame -----------------------------------------------------

    public function testTheAdvancedTabRidesTheOpenFormStrip(): void
    {
        // Nine tabs overflow a 100-column strip (it clips to 'Scripts ›'),
        // so the strip probe widens until every label fits.
        $frame = Ansi::strip(MediaForm::open()->view(Theme::default(), 140, 40));

        $this->assertStringContainsString(Lang::t(GenFormKeys::SECTION_ADVANCED), $frame);
    }

    public function testTwoSeededRowsRenderTheGoldenFrame(): void
    {
        $section = $this->advancedSection(['sd_model_checkpoint' => 'v1.5', 'uni_pc_order' => 50]);
        $frame = Ansi::strip(
            MediaForm::fromSections([$section], MediaRequest::new())
                ->click(MediaForm::TAB_ZONE . '0')
                ->view(Theme::default(), 80, 24),
        );

        $this->assertAdvancedGolden('advanced-rows-80x24.txt', $frame);
        $this->assertStringContainsString(Lang::t(GenFormKeys::ADVANCED_REMOVE), $frame);
        $this->assertStringContainsString(Lang::t(GenFormKeys::ADVANCED_RESTORE_AFTERWARDS), $frame);
    }

    // ---- helpers (uniquely named for the drift guard) ------------------------------

    /**
     * @param array<string, mixed> $overrideSettings
     */
    private function advancedSection(array $overrideSettings): AdvancedSection
    {
        $draft = $overrideSettings === []
            ? MediaRequest::new()
            : MediaRequest::fromArray(['override_settings' => $overrideSettings]);

        return AdvancedSection::forDraft($draft, $this->advancedOptionsMap());
    }

    /**
     * Stands in for the lazily-fetched GET /sdapi/v1/options payload with one
     * entry per inferable type (the A1111 clone is absent on this box, so the
     * map is hand-built rather than recorded).
     *
     * @return array<string, mixed>
     */
    private function advancedOptionsMap(): array
    {
        return [
            'sd_model_checkpoint' => 'v1.5',
            'uni_pc_order' => 50,
            'sigma_max' => 0.75,
            'use_adaptive_noise' => true,
        ];
    }

    /**
     * The completion pool an Input row was configured with (its inner
     * TextInput carries the candidate list).
     *
     * @return list<string>
     */
    private function advancedInputCandidates(Input $field): array
    {
        $inner = (new \ReflectionProperty(Input::class, 'input'))->getValue($field);

        return $inner->suggestions;
    }

    private function advancedField(AdvancedSection $section, string $key): \SugarCraft\Forms\Field
    {
        $field = $this->advancedFieldOrNull($section, $key);
        $this->assertNotNull($field, "row {$key} is not on screen");

        return $field;
    }

    private function advancedFieldOrNull(AdvancedSection $section, string $key): ?\SugarCraft\Forms\Field
    {
        for ($row = 0; $row < count($section->rows()); $row++) {
            if ($section->fieldAt($row)?->key() === $key) {
                return $section->fieldAt($row);
            }
        }

        return null;
    }

    /** The value cell a row key currently shows. */
    private function advancedCellText(AdvancedSection $section, string $key): string
    {
        for ($row = 0; $row < count($section->rows()); $row++) {
            if ($section->fieldAt($row)?->key() === $key) {
                return $section->valueAt($row);
            }
        }

        $this->fail("row {$key} is not on screen");
    }

    private function assertAdvancedGolden(string $name, string $actual): void
    {
        $path = self::ADVANCED_GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!is_dir(self::ADVANCED_GOLDEN_DIR)) {
                mkdir(self::ADVANCED_GOLDEN_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}
