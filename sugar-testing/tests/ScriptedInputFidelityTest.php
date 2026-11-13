<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\Input\TickMsg;

/**
 * Fidelity pins for the scripting surface: what {@see ScriptedInput::build()}
 * yields must be exactly what gets queued — the harness TickMsg only when the
 * caller wants the harness type, the caller's own class when a factory is
 * supplied, and modifier flags the caller set (arrows and named keys used to
 * hard-code them to false, silently mis-modifying every chord script).
 */
final class ScriptedInputFidelityTest extends TestCase
{
    public function testTicksWithoutFactoryThrowsTheNamedRefusal(): void
    {
        // The vacuous-tick trap is enforced, not merely warned: a factory is
        // required, so a bare ticks() can never enqueue harness TickMsgs that
        // match nothing in a lib that ships its own class.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ticks() requires a tick factory');

        ScriptedInput::new()->ticks(3);
    }

    public function testTicksFactoryYieldsTheCallerTickClass(): void
    {
        // Fleet reality: every consumer lib ships its OWN tick message, so
        // the factory is the documented way to script ticks that a model
        // will actually match. The harness type is just one candidate.
        $custom = new class implements Msg {
        };

        $seen = [];
        $msgs = ScriptedInput::new()
            ->ticks(2, seconds: 0.25, factory: static function (int $index, float $seconds) use ($custom, &$seen): Msg {
                $seen[] = [$index, $seconds];

                return $custom;
            })
            ->build();

        self::assertCount(2, $msgs);
        self::assertSame([$custom, $custom], $msgs);
        self::assertSame([[0, 0.25], [1, 0.25]], $seen);
    }

    public function testTicksFactoryReceivesZeroBasedIndexes(): void
    {
        $seen = [];
        ScriptedInput::new()->ticks(3, factory: static function (int $index, float $seconds) use (&$seen): Msg {
            $seen[] = $index;

            return new TickMsg($seconds);
        })->build();

        self::assertSame([0, 1, 2], $seen);
    }

    public function testNamedKeyCarriesModifierFlags(): void
    {
        $msgs = ScriptedInput::new()->namedKey(KeyType::Enter, shift: true, ctrl: true, alt: true)->build();

        self::assertCount(1, $msgs);
        $msg = $msgs[0];
        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertSame(KeyType::Enter, $msg->type);
        self::assertTrue($msg->shift, 'namedKey() dropped the shift flag');
        self::assertTrue($msg->ctrl, 'namedKey() dropped the ctrl flag');
        self::assertTrue($msg->alt, 'namedKey() dropped the alt flag');
    }

    public function testNamedKeyDefaultsAreUnmodified(): void
    {
        $msg = ScriptedInput::new()->namedKey(KeyType::Tab)->build()[0];

        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertFalse($msg->shift);
        self::assertFalse($msg->ctrl);
        self::assertFalse($msg->alt);
    }

    public function testArrowCarriesModifierFlags(): void
    {
        $msg = ScriptedInput::new()->arrow('down', shift: true)->build()[0];

        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertSame(KeyType::Down, $msg->type);
        self::assertTrue($msg->shift, 'arrow() dropped the shift flag');
        self::assertFalse($msg->ctrl);
    }

    public function testArrowWithoutModifiersStaysPlain(): void
    {
        $msg = ScriptedInput::new()->arrow('left')->build()[0];

        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertSame(KeyType::Left, $msg->type);
        self::assertFalse($msg->shift);
        self::assertFalse($msg->ctrl);
        self::assertFalse($msg->alt);
    }
}
