<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * E736 plan 5.4 (round 89): per-field key overrides on the input domain —
 * TextInput + TextArea widgets and the Input / Text field pass-throughs.
 *
 * Four families: the parse DOOR (fail-loud, set-time), precedence (override
 * wins over the vim branch and every built-in arm; unbound keys fall through),
 * semantics (each registry action runs its real primitive), and carriage (the
 * map rides the widget's mutate funnel across ordinary edits).
 */
final class KeyOverridesTest extends TestCase
{
    private function focused(?string $initial = null): TextInput
    {
        $t = TextInput::new();
        if ($initial !== null) {
            $t = $t->setValue($initial); // parks the caret at end
        }

        return $t->focus()[0];
    }

    /** @param array<string,string> $map */
    private function ch(string $rune, bool $ctrl = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, false, $ctrl);
    }

    private function type(TextInput $t, KeyMsg $msg): TextInput
    {
        $next = $t->update($msg)[0];
        $this->assertInstanceOf(TextInput::class, $next);

        return $next;
    }

    // ---- parse door -------------------------------------------------------

    public function testDefaultIsNoOverrides(): void
    {
        $this->assertSame([], TextInput::new()->keyOverrides);
        $this->assertSame([], TextArea::new()->keyOverrides());
    }

    public function testCanonicalisationFoldsCaseOrderAndAliases(): void
    {
        $t = $this->focused()->withKeyOverrides([
            'CTRL+U'     => 'move_end',
            'alt+ctrl+x' => 'noop',
            'esc'        => 'move_start',
            'PGDN'       => 'move_left',
        ]);
        $this->assertSame([
            'ctrl+u'   => 'move_end',
            'ctrl+alt+x' => 'noop',
            'escape'   => 'move_start',
            'pagedown' => 'move_left',
        ], $t->keyOverrides());
    }

    public function testUnknownSpellingThrows(): void
    {
        try {
            TextInput::new()->withKeyOverrides(['hyper+key' => 'noop']);
            $this->fail('unknown spelling must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown key override spelling', $e->getMessage());
        }
    }

    public function testUnknownActionThrows(): void
    {
        try {
            TextInput::new()->withKeyOverrides(['ctrl+u' => 'launch_missiles']);
            $this->fail('unknown action must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown key override action', $e->getMessage());
        }
    }

    public function testCollidingSpellingsThrow(): void
    {
        try {
            TextInput::new()->withKeyOverrides([
                'ctrl+alt+x' => 'noop',
                'alt+ctrl+x' => 'move_end',
            ]);
            $this->fail('two spellings of one canonical binding must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Duplicate key override', $e->getMessage());
        }
    }

    public function testNonStringSpellingThrows(): void
    {
        try {
            /** @var array<string,string> $poison — deliberately wrong shape for the door test */
            $poison = [5 => 'noop'];
            TextInput::new()->withKeyOverrides($poison);
            $this->fail('non-string spelling must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('map of string spellings', $e->getMessage());
        }
    }

    public function testWitherIsImmutable(): void
    {
        $t  = TextInput::new();
        $t2 = $t->withKeyOverrides(['ctrl+u' => 'noop']);
        $this->assertNotSame($t, $t2);
        $this->assertSame([], $t->keyOverrides);
    }

    // ---- precedence -------------------------------------------------------

    public function testOverrideWinsOverBuiltInCtrlArm(): void
    {
        // Built-in ctrl+u deletes everything left of the caret; the override
        // must MOVE instead, leaving the buffer intact.
        $t = $this->focused('hello')->withKeyOverrides(['ctrl+u' => 'move_end']);
        // Vacuity pair first: unbound ctrl+u really does clear.
        $cleared = $this->type($this->focused('hello'), $this->ch('u', true));
        $this->assertSame('', $cleared->value);

        $next = $this->type($t, $this->ch('u', true));
        $this->assertSame('hello', $next->value);
        $this->assertSame(5, $next->cursorPos);
    }

    public function testUnboundKeysFallThroughToBuiltIns(): void
    {
        $t = $this->focused('abc')->withKeyOverrides(['q' => 'noop']);
        $this->assertSame('', $this->type($t, $this->ch('u', true))->value);
    }

    public function testNoopSwallowsCharThatBuiltInWouldInsert(): void
    {
        $this->assertSame('z', $this->type($this->focused(''), $this->ch('z'))->value);
        $t = $this->focused('')->withKeyOverrides(['z' => 'noop']);
        $this->assertSame('', $this->type($t, $this->ch('z'))->value);
    }

    public function testOverrideWinsOverVimBranch(): void
    {
        // Park the caret at index 1 (over 'b') without vim, then enable vim.
        $t = $this->focused('abx');
        $t = $this->type($t, new KeyMsg(KeyType::Left));
        $t = $this->type($t, new KeyMsg(KeyType::Left));
        $this->assertSame(1, $t->cursorPos);
        $t = $t->withVimMode(true);

        // Vacuity pair: unbound vim-normal 'x' deletes under the caret.
        $this->assertSame('ax', $this->type($t, $this->ch('x'))->value);

        // Bound to move_end, the same keystroke moves WITHOUT touching text.
        $bound  = $t->withKeyOverrides(['x' => 'move_end']);
        $next   = $this->type($bound, $this->ch('x'));
        $this->assertSame('abx', $next->value);
        $this->assertSame(3, $next->cursorPos);
    }

    public function testOverridesInertWhileUnfocused(): void
    {
        $t = TextInput::new()->setValue('abc')->withKeyOverrides(['z' => 'move_end']);
        $next = $t->update($this->ch('z'))[0];
        $this->assertSame($t, $next, 'the pre-existing focus guard must still run first');
    }

    // ---- semantics --------------------------------------------------------

    public function testHistoryActionReachableUnderForeignBinding(): void
    {
        $t    = $this->focused('')->withHistory(['one', 'two'])->withKeyOverrides(['f5' => 'history_up']);
        $next = $t->update(new KeyMsg(KeyType::F5))[0];
        $this->assertInstanceOf(TextInput::class, $next);
        $this->assertSame('two', $next->value);
        $this->assertSame(3, $next->cursorPos);
    }

    public function testEveryTextInputActionRunsItsRealPrimitive(): void
    {
        // Caret at index 2 of "123" everywhere (setValue parks end, one Left).
        $expect = [
            'move_start'      => ['123', 0],
            'move_end'        => ['123', 3],
            'move_left'       => ['123', 1],
            'move_right'      => ['123', 3],
            'backspace'       => ['13', 1],
            'delete_forward'  => ['12', 2],
            'delete_to_start' => ['3', 0],
            'delete_to_end'   => ['12', 2],
            'noop'            => ['123', 2],
        ];
        foreach ($expect as $action => [$value, $cursor]) {
            $t = $this->focused('123');
            $t = $this->type($t, new KeyMsg(KeyType::Left)); // caret 2
            $t = $t->withKeyOverrides(['f3' => $action]);
            $next = $this->type($t, new KeyMsg(KeyType::F3));
            $this->assertSame($value, $next->value, "action {$action} value");
            $this->assertSame($cursor, $next->cursorPos, "action {$action} cursor");
        }
    }

    // ---- carriage ---------------------------------------------------------

    public function testOverridesRideOrdinaryEdits(): void
    {
        $t    = $this->focused('')->withKeyOverrides(['ctrl+u' => 'noop']);
        $next = $this->type($t, $this->ch('a'));
        $next = $this->type($next, $this->ch('b'));
        $this->assertSame(['ctrl+u' => 'noop'], $next->keyOverrides);
        $this->assertSame('ab', $this->type($next, $this->ch('u', true))->value);
    }

    // ---- Input field pass-through -----------------------------------------

    public function testInputFieldDelegatesToInnerWidget(): void
    {
        $f = Input::new('handle')->withValue('abcd')->withKeyOverrides(['ctrl+u' => 'delete_forward']);
        $this->assertSame(['ctrl+u' => 'delete_forward'], $f->keyOverrides());
        $f = $f->focus()[0];
        $this->assertInstanceOf(Input::class, $f);
        // Vacuity: built-in ctrl+u at caret end would clear the value.
        $this->assertSame('', $f->withKeyOverrides([])->update($this->ch('u', true))[0]->value());

        $next = $f->update($this->ch('u', true))[0];
        $this->assertInstanceOf(Input::class, $next);
        $this->assertSame('abcd', $next->value(), 'override delete_forward at end is inert');
    }

    // ---- TextArea / Text field pass-through --------------------------------

    public function testTextAreaRowSemanticsAndFieldPassThrough(): void
    {
        $area = TextArea::new()->setValue("abc\ndef")->focus()[0];
        $this->assertInstanceOf(TextArea::class, $area);
        $area = $area->withKeyOverrides(['ctrl+u' => 'move_start']);

        // Vacuity: built-in ctrl+u kills the caret's line tail.
        $plain = $area->withKeyOverrides([])->update($this->ch('u', true))[0];
        $this->assertInstanceOf(TextArea::class, $plain);
        $this->assertSame(['abc', ''], $plain->lines);

        $next = $area->update($this->ch('u', true))[0];
        $this->assertInstanceOf(TextArea::class, $next);
        $this->assertSame(['abc', 'def'], $next->lines, 'row-relative move_start keeps text');
        $this->assertSame(1, $next->row);
        $this->assertSame(0, $next->col);

        $f = Text::new('notes')->withKeyOverrides(['ctrl+y' => 'noop']);
        $this->assertSame(['ctrl+y' => 'noop'], $f->keyOverrides());
    }

    public function testTextAreaDoorRejectsHistoryActions(): void
    {
        try {
            TextArea::new()->withKeyOverrides(['up' => 'history_up']);
            $this->fail('TextArea carries no history — the door must reject history_up');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown key override action', $e->getMessage());
        }
    }
}
