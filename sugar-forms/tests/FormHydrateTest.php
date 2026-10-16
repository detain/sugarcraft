<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use PHPUnit\Framework\TestCase;

/**
 * E736 5.9 (round 89) — Form::hydrate() and the values() round trip.
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

    public function testUnhydratableConcreteFieldFailsLoud(): void
    {
        // FilePicker is the library's own non-value field: navigation state,
        // no setter — hydrate must name it, never pretend success.
        $withPicker = Form::new(\SugarCraft\Forms\Field\FilePicker::new('p', sys_get_temp_dir()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('p');
        $withPicker->hydrate(['p' => '/nonexistent']);
    }
}

enum HydrateColour: string
{
    case Red   = 'red';
    case Green = 'green';
}
