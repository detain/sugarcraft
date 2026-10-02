<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspExchangeState;

/**
 * The value an LspConnection exchange hands to the next one (audit B7).
 * Pinned here: the encoding round-trips every field (a raw stdout buffer is
 * arbitrary bytes), anything unreadable decodes as the one state that trusts
 * no buffer, and the withers leave the original untouched.
 */
final class LspExchangeStateTest extends TestCase
{
    public function testAFreshStateIsCleanWithNothingOwed(): void
    {
        $state = LspExchangeState::new();

        self::assertSame(LspExchangeState::PHASE_CLEAN, $state->phase);
        self::assertSame('', $state->buffer);
        self::assertFalse($state->broken);
        self::assertFalse($state->owesFrame());
        self::assertSame(0, $state->noteSeq);
    }

    public function testEveryFieldSurvivesEncodeAndDecode(): void
    {
        $buffer = "Content-Length: 3\r\n\r\n{\"\x00\xff";
        $state = LspExchangeState::new()
            ->withPhase(LspExchangeState::PHASE_WRITING)
            ->withBuffer($buffer)
            ->withBroken(true)
            ->withFrame(100, 40, true)
            ->withNoteSeq(7);

        $decoded = LspExchangeState::decode($state->encode());

        self::assertSame(LspExchangeState::PHASE_WRITING, $decoded->phase);
        self::assertSame($buffer, $decoded->buffer);
        self::assertTrue($decoded->broken);
        self::assertSame(100, $decoded->frameLength);
        self::assertSame(40, $decoded->frameWritten);
        self::assertTrue($decoded->frameInflight);
        self::assertSame(7, $decoded->noteSeq);
    }

    public function testAnUnreadableStateDecodesAsADeadReader(): void
    {
        foreach (['', 'garbage', serialize(['phase' => 'Z', 'buffer' => 'x']), serialize('C')] as $raw) {
            $state = LspExchangeState::decode($raw);

            self::assertSame(LspExchangeState::PHASE_READING, $state->phase, var_export($raw, true));
            self::assertSame('', $state->buffer, 'a buffer nobody can vouch for is never handed on');
        }
    }

    public function testWithersReturnNewInstances(): void
    {
        $original = LspExchangeState::new();

        $changed = $original->withFrame(10, 4, false);

        self::assertNotSame($original, $changed);
        self::assertSame(0, $original->frameLength);
        self::assertTrue($changed->owesFrame());
        self::assertFalse($changed->withFrame(10, 10, false)->owesFrame(), 'a fully sent frame owes nothing');
        self::assertFalse($changed->withoutFrame()->owesFrame());
    }
}
