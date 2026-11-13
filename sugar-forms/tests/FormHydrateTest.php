<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use PHPUnit\Framework\TestCase;

/**
 * E736 5.9 (round 89) — Form::hydrate() and the values() round trip.
 * SF-23 (sugar-forms-025) moved dispatch from a closed match over the
 * concrete final classes onto the Hydratable capability interface: every
 * shipped value field — including the three the match forgot — round-trips
 * its own export, and third-party fields opt in.
 */
final class FormHydrateTest extends TestCase
{
    public function testRoundTripPreservesEveryScalarField(): void
    {
        $form = Form::new(
            Input::new('name'),
            Text::new('bio'),
            Confirm::new('subscribe'),
            Select::new('size')->withOptions('S', 'M', 'L'),
            MultiSelect::new('toppings')->withOptions('a', 'b', 'c'),
        );

        $snapshot = [
            'name'      => 'Ada',
            'bio'       => "line1\nline2",
            'subscribe' => true,
            'size'      => 'M',
            'toppings'  => ['a', 'c'],
        ];

        $hydrated = $form->hydrate($snapshot);
        $this->assertSame($snapshot, $hydrated->values());
        // values() ↔ hydrate() symmetry: re-exporting gives the map back.
        $this->assertSame($snapshot, $form->hydrate($hydrated->values())->values());
        // Fluent law: the original is untouched.
        $this->assertSame('', $form->get('name'));
    }

    public function testHydrateAcrossGroups(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Input::new('b'), Confirm::new('c')),
        );
        $next = $form->hydrate(['a' => 'one', 'b' => 'two', 'c' => true]);
        $this->assertSame(['a' => 'one', 'b' => 'two', 'c' => true], $next->values());
    }

    public function testEmptyMapEarlyExitsToIdentity(): void
    {
        $form = Form::new(Input::new('a'));
        $this->assertSame($form, $form->hydrate([]));
    }

    public function testHydrateSurfacesAbsentSelectOptionAsNoteNotThrow(): void
    {
        // SF-22 (sugar-forms-072): withSelected() is a documented public
        // no-op for absent values, and hydrate routed through it — a drifted
        // snapshot diverged SILENTLY. The restore keeps succeeding (no throw
        // conversion); the divergence is told on the returned form.
        $form = Form::new(Select::new('size')->withOptions('S', 'M', 'L'));
        $next = $form->hydrate(['size' => 'XL']);

        $this->assertNotSame($form, $next, 'hydrate stays fluent');
        $this->assertSame('S', $next->values()['size'], 'absent option leaves the selection untouched');
        $this->assertSame(['size'], array_keys($next->hydrateNotes), 'the decline is told, not swallowed');
        $this->assertStringContainsString('XL', $next->hydrateNotes['size']);

        // A later clean hydrate replaces the slot.
        $clean = $next->hydrate(['size' => 'M']);
        $this->assertSame([], $clean->hydrateNotes);
        $this->assertSame('M', $clean->values()['size']);
    }

    public function testValuesFramePathSurvivesDriftedEnumPool(): void
    {
        // SF-22 (sugar-forms-024): value() projected via from(), so a pool
        // drifted off the enum threw ValueError INSIDE the frame path
        // Form::values(). tryFrom maps the unmappable selection to null.
        $form = Form::new(
            Select::new('mode')->withOptions('zzz', 'red')->withEnum(HydrateColour::class),
        );
        $this->assertSame(['mode' => null], $form->values());

        // The happy path still projects.
        $ok = Select::new('mode')->withOptions('red', 'zzz')->withEnum(HydrateColour::class);
        $this->assertSame(HydrateColour::Red, $ok->value());
    }

    public function testUnknownKeyFailsLoudAndLeavesFormUntouched(): void
    {
        $form = Form::new(Input::new('a'), Input::new('b'));
        try {
            // 'a' is valid and listed FIRST — atomicity means it must not land.
            $form->hydrate(['a' => 'written', 'ghost' => 'x']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ghost', $e->getMessage());
        }
        $this->assertSame('', $form->get('a'));
    }

    public function testSkippableKeyIsRefused(): void
    {
        $form = Form::new(Input::new('a'), Note::new('mid'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mid');
        $form->hydrate(['mid' => 'nope']);
    }

    public function testWrongTypeFailsLoudNamingKey(): void
    {
        $form = Form::new(
            Input::new('a'),
            Confirm::new('b'),
            MultiSelect::new('c')->withOptions('x'),
        );
        // Asserted by hand (no expectException): three legs in one test, and
        // a caught-and-checked throw still has to be the RIGHT message.
        foreach ([
            ['a', true],        // bool never stringifies into a text field
            ['b', 'yes'],       // Confirm needs a real bool
            ['c', 'x'],         // MultiSelect needs the list
        ] as [$key, $raw]) {
            try {
                $form->hydrate([$key => $raw]);
                $this->fail("expected type rejection for {$key}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testMultiSelectUnknownOptionFailsLoud(): void
    {
        $form = Form::new(MultiSelect::new('c')->withOptions('x', 'y'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ghost-option');
        $form->hydrate(['c' => ['x', 'ghost-option']]);
    }

    public function testHydratedMultiSelectSurfacesStaleConstraintViolation(): void
    {
        // A saved snapshot that no longer fits a tightened cap must SURFACE
        // via the constraint error, not silently drop the saved picks.
        $form = Form::new(MultiSelect::new('c')->withOptions('x', 'y')->withMax(1));
        $next = $form->hydrate(['c' => ['x', 'y']]);
        $this->assertSame(['x', 'y'], $next->get('c'));
        $this->assertNotNull($next->activeFields()[0]->getError());
        $this->assertStringContainsString('at most 1', (string) $next->activeFields()[0]->getError());
    }

    public function testSelectEnumSnapshotUnwrapsToItsValue(): void
    {
        $form = Form::new(Select::new('s')->withOptions('red', 'green'));
        $next = $form->hydrate(['s' => HydrateColour::Green]);
        $this->assertSame('green', $next->get('s'));
    }

    public function testFilePickerRoundTripsItsSelection(): void
    {
        // SF-23 (sugar-forms-025): FilePicker was a match arm the closed
        // world forgot — its value IS a persisted datum (the selected
        // path); navigation state deliberately stays out of the snapshot.
        $form = Form::new(FilePicker::new('p', sys_get_temp_dir()));
        $next = $form->hydrate(['p' => '/x/y.txt']);
        $this->assertSame('/x/y.txt', $next->values()['p']);
        $this->assertSame(['p' => '/x/y.txt'], $next->hydrate($next->values())->values());
        $this->assertNull($next->hydrate(['p' => null])->values()['p']);
        try {
            $form->hydrate(['p' => true]);
            $this->fail('expected type rejection for the picker');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('p', $e->getMessage());
        }
    }

    public function testNumberFieldRestoresEmptyBoxAndRefusesStrings(): void
    {
        // SF-23: null exports as the never-set empty box and restores as
        // one; a numeric string is NOT quietly coerced into the field.
        $form = Form::new(NumberField::new('n')->withValue(7));
        $this->assertSame(7, $form->values()['n']);
        $this->assertSame(4, $form->hydrate(['n' => 3.5])->values()['n']); // off-lattice float commits onto the step lattice (round, not truncate)

        $this->assertNull($form->hydrate(['n' => null])->values()['n']);
        try {
            $form->hydrate(['n' => '7']);
            $this->fail('expected type rejection for the number box');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('int|float|null', $e->getMessage());
        }
    }

    public function testRadioPillsRestoresCursorAndTellsDriftedLabel(): void
    {
        // SF-23: a pill row restores by label through the same notes
        // channel SF-22 opened for Select — told, not thrown, on drift.
        $form = Form::new(RadioPills::new('r')->withOptions('one', 'two', 'three'));
        $this->assertSame('one', $form->values()['r']);
        $this->assertSame('two', $form->hydrate(['r' => 'two'])->values()['r']);
        $drift = $form->hydrate(['r' => 'gone']);
        $this->assertSame('one', $drift->values()['r']);
        $this->assertSame(['r'], array_keys($drift->hydrateNotes));
        $this->assertStringContainsString('gone', $drift->hydrateNotes['r']);
    }

    public function testEveryShippedValueFieldRoundTripsItsOwnExport(): void
    {
        // The class bug SF-23 closes: values() exported every non-skippable
        // field, hydrate() answered only its own match list. One of every
        // shipped value field now saves and restores untouched.
        $form = Form::groups(
            Group::new(
                Input::new('in'),
                Text::new('tx'),
                \SugarCraft\Forms\Field\Color::new('col'),
                \SugarCraft\Forms\Field\Slider::new('sl'),
                \SugarCraft\Forms\Field\Date::new('dt'),
                Select::new('se')->withOptions('a', 'b'),
            ),
            Group::new(
                MultiSelect::new('ms')->withOptions('a', 'b'),
                Confirm::new('cf'),
                NumberField::new('nf'),
                RadioPills::new('rp')->withOptions('x', 'y'),
                FilePicker::new('fp', sys_get_temp_dir()),
            ),
        );
        $snapshot = $form->hydrate([
            'in' => 'abc', 'tx' => 'body', 'col' => '#ff0000', 'sl' => 2, 'dt' => null,
            'se' => 'b', 'ms' => ['a'], 'cf' => true, 'nf' => 9, 'rp' => 'y', 'fp' => '/tmp/sel.txt',
        ])->values();
        $again = $form->hydrate($snapshot)->values();
        $this->assertSame($snapshot, $again);
    }

    public function testCustomHydratableFieldHydratesLikeAShippedOne(): void
    {
        // The whole point of the hook: the world is open.
        $form = Form::new(new GhostDraft('g'));
        $next = $form->hydrate(['g' => 'restored']);
        $this->assertSame('restored', $next->get('g'));
    }

    public function testPlainFieldImplementationStillFailsLoud(): void
    {
        // A third-party Field that never opted in compiles against the
        // un-widened interface (BC) and still refuses by name — an error
        // message, not a silent miss.
        $form = Form::new(new PlainSketch('p'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('p');
        $form->hydrate(['p' => 'x']);
    }
}

/**
 * Minimal third-party Hydratable field (SF-23): proof the open world
 * round-trips. Records the snapshot it was restored from.
 */
final class GhostDraft implements \SugarCraft\Forms\Hydratable
{
    public function __construct(private readonly string $k, private readonly string $v = '') {}

    public function key(): string       { return $this->k; }
    public function value(): mixed      { return $this->v; }
    public function focus(): array      { return [$this, null]; }
    public function blur(): \SugarCraft\Forms\Field { return $this; }
    public function update(\SugarCraft\Core\Msg $msg): array { return [$this, null]; }
    public function view(bool $showErrors = true, ?\SugarCraft\Forms\Theme $theme = null): string { return $this->v; }
    public function isFocused(): bool   { return false; }
    public function getTitle(): string  { return $this->k; }
    public function description(): string { return ''; }
    public function error(): ?string    { return null; }
    public function getDescription(): string { return ''; }
    public function getError(): ?string { return null; }
    public function skippable(): bool   { return false; }
    public function consumes(\SugarCraft\Core\Msg $msg): bool { return false; }
    public function isHidden(array $values): bool { return false; }
    public function revalidate(): \SugarCraft\Forms\Field { return $this; }

    public function hydrateValue(mixed $raw, array &$notes = []): static
    {
        return new self($this->k, (string) $raw);
    }
}

/**
 * A Field implementation WITHOUT the hook — its mere compilation is the
 * BC proof SF-23 owed third-party implementors (the interface stayed
 * un-widened; hydration refuses it by name instead).
 */
final class PlainSketch implements \SugarCraft\Forms\Field
{
    public function __construct(private readonly string $k) {}

    public function key(): string       { return $this->k; }
    public function value(): mixed      { return ''; }
    public function focus(): array      { return [$this, null]; }
    public function blur(): \SugarCraft\Forms\Field { return $this; }
    public function update(\SugarCraft\Core\Msg $msg): array { return [$this, null]; }
    public function view(bool $showErrors = true, ?\SugarCraft\Forms\Theme $theme = null): string { return ''; }
    public function isFocused(): bool   { return false; }
    public function getTitle(): string  { return $this->k; }
    public function description(): string { return ''; }
    public function error(): ?string    { return null; }
    public function getDescription(): string { return ''; }
    public function getError(): ?string { return null; }
    public function skippable(): bool   { return false; }
    public function consumes(\SugarCraft\Core\Msg $msg): bool { return false; }
    public function isHidden(array $values): bool { return false; }
    public function revalidate(): \SugarCraft\Forms\Field { return $this; }
}

enum HydrateColour: string
{
    case Red   = 'red';
    case Green = 'green';
}
