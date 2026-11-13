<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Modifiers;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\Modifiable;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\KeyType;

/**
 * sc-23 (finding sugar-core-024): one modifier idiom observable from
 * BOTH event families. The mouse family stored bare booleans and had no
 * bundler; the key family had the bundler but no shared predicate.
 * {@see Modifiable} is the single way to ask.
 */
final class ModifierParityTest extends TestCase
{
    /** @return array<string,array{0:bool,1:bool,2:bool}> */
    public static function boolCombos(): array
    {
        $out = [];
        foreach ([false, true] as $shift) {
            foreach ([false, true] as $alt) {
                foreach ([false, true] as $ctrl) {
                    $out[sprintf('s%d a%d c%d', $shift, $alt, $ctrl)] = [$shift, $alt, $ctrl];
                }
            }
        }
        return $out;
    }

    /**
     * @dataProvider boolCombos
     */
    public function testKeyAndMouseAnswerIdenticallyForEveryCombo(bool $shift, bool $alt, bool $ctrl): void
    {
        $key   = new KeyMsg(KeyType::Char, 'x', alt: $alt, ctrl: $ctrl, shift: $shift);
        $mouse = new MouseMsg(10, 20, MouseButton::Left, MouseAction::Press, shift: $shift, alt: $alt, ctrl: $ctrl);

        $this->assertInstanceOf(Modifiable::class, $key);
        $this->assertInstanceOf(Modifiable::class, $mouse);

        foreach ([Modifiers::SHIFT => $shift, Modifiers::ALT => $alt, Modifiers::CTRL => $ctrl] as $bit => $expected) {
            $this->assertSame($expected, $key->has($bit), "key arm bit {$bit}");
            $this->assertSame($expected, $mouse->has($bit), "mouse arm bit {$bit}");
        }

        // The bundled values agree across families exactly.
        $this->assertSame(
            $key->modifiers()->toBitfield(),
            $mouse->modifiers()->toBitfield()
        );
    }

    public function testConcreteMouseSubclassesCarryTheParity(): void
    {
        $click = new MouseClickMsg(3, 4, MouseButton::Right, MouseAction::Press, ctrl: true);
        $this->assertTrue($click->has(Modifiers::CTRL));
        $this->assertFalse($click->has(Modifiers::ALT));
    }

    public function testPublicBooleansStayUnchanged(): void
    {
        // Additive law: the interface did NOT replace the stored fields.
        $mouse = new MouseMsg(1, 2, MouseButton::Left, MouseAction::Press, alt: true);
        $key   = new KeyMsg(KeyType::Enter, alt: true);
        $this->assertTrue($mouse->alt);
        $this->assertTrue($key->alt);
    }

    public function testModifiersHasReadsItsOwnFields(): void
    {
        $m = Modifiers::of(shift: true, ctrl: true);
        $this->assertTrue($m->has(Modifiers::SHIFT));
        $this->assertTrue($m->has(Modifiers::CTRL));
        $this->assertFalse($m->has(Modifiers::ALT));
    }

    public function testUnknownModifierBitThrowsNamed(): void
    {
        try {
            Modifiers::none()->has(99);
            $this->fail('an unknown modifier bit read as "not held"');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unknown modifier bit 99', $e->getMessage());
        }
    }
}
