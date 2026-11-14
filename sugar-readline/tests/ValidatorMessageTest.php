<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Core\Util\Width;

/**
 * S-33 (finding 035): a validator may return a string and it becomes the
 * painted rejection reason; throwing is contained as the default message.
 */
final class ValidatorMessageTest extends TestCase
{
    /** Strip SGR codes so row maths see plain cells. */
    private static function plain(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    public function testStringVerdictIsPaintedAsTheReason(): void
    {
        $p = TextPrompt::new('Password: ')
            ->withValidator(static fn(string $v): bool|string => strlen($v) >= 8 ? true : 'must be at least 8 characters')
            ->handleChar('a')->handleChar('b')
            ->handleKey(Key::Enter);

        $this->assertFalse($p->isSubmitted());
        $this->assertSame('must be at least 8 characters', $p->error());
        $this->assertStringContainsString('must be at least 8 characters', self::plain($p->view()));
        $this->assertStringNotContainsString('Invalid input', self::plain($p->view()));
    }

    public function testAcceptedOnceRuleSatisfied(): void
    {
        $p = TextPrompt::new('Password: ')
            ->withValidator(static fn(string $v): bool|string => strlen($v) >= 8 ? true : 'must be at least 8 characters');
        foreach (str_split('longenough') as $ch) {
            $p = $p->handleChar($ch);
        }
        $p = $p->handleKey(Key::Enter);

        $this->assertTrue($p->isSubmitted());
        $this->assertSame('', $p->error(), 'a successful submit clears the reason');
    }

    public function testFalseRejectsKeepsDefaultInvalidInputMessage(): void
    {
        $p = TextPrompt::new('> ')->withValidator(static fn(string $v): bool => false)->handleKey(Key::Enter);

        $this->assertSame('Invalid input', $p->error(), 'BC: the bool contract message is untouched');
    }

    public function testEmptyStringRejectsDefaultMessage(): void
    {
        $p = TextPrompt::new('> ')->withValidator(static fn(string $v): string => '')->handleKey(Key::Enter);

        $this->assertFalse($p->isSubmitted());
        $this->assertSame('Invalid input', $p->error());
    }

    public function testTruthyNonStringAccepts(): void
    {
        $p = TextPrompt::new('> ')->withValidator(static fn(string $v): int => 7)->handleKey(Key::Enter);

        $this->assertTrue($p->isSubmitted());
    }

    public function testFalsyNonStringRejectsDefault(): void
    {
        $p = TextPrompt::new('> ')->withValidator(static fn(string $v): int => 0)->handleKey(Key::Enter);

        $this->assertFalse($p->isSubmitted());
        $this->assertSame('Invalid input', $p->error());
    }

    public function testThrowingValidatorIsContainedNotEscaped(): void
    {
        // The pre-S-33 behaviour was an exception escaping submit()/run();
        // the runtime backstop turns it into a default-message rejection.
        $p = TextPrompt::new('> ')
            ->withValidator(static function (string $v): bool {
                throw new \RuntimeException('rule engine exploded');
            })
            ->handleKey(Key::Enter);

        $this->assertFalse($p->isSubmitted());
        $this->assertSame('Invalid input', $p->error());
        // Exception detail must not leak onto the wire.
        $this->assertStringNotContainsString('exploded', self::plain($p->view()));
    }

    public function testReasonRowIsCellClippedToBound(): void
    {
        $p = TextPrompt::new('> ')
            ->withDisplayWidth(20)
            ->withValidator(static fn(string $v): string => 'this reason is far too wide for the twenty cell viewport')
            ->handleKey(Key::Enter);

        $rows = explode("\n", self::plain($p->view()));
        $reason = end($rows);

        $this->assertLessThanOrEqual(20, Width::of($reason), 'error row must never wrap the repaint');
        $this->assertStringContainsString('…', $reason);
        $this->assertStringNotContainsString('viewport', $reason, 'the clipped tail must not reach the wire');
    }

    public function testUnboundedReasonRendersRawAsBefore(): void
    {
        $long = str_repeat('x', 120);
        $p = TextPrompt::new('> ')
            ->withValidator(static fn(string $v): string => $long)
            ->handleKey(Key::Enter);

        $this->assertStringContainsString($long, self::plain($p->view()), 'null bound paints the reason unbounded (legacy shape)');
    }

    public function testTypingAfterRejectionClearsTheReason(): void
    {
        $p = TextPrompt::new('> ')
            ->withValidator(static fn(string $v): bool|string => $v === 'ok' ? true : 'say ok')
            ->handleKey(Key::Enter);
        $this->assertSame('say ok', $p->error());

        $p = $p->handleChar('o');
        $this->assertSame('', $p->error());
        $this->assertStringNotContainsString('say ok', self::plain($p->view()));
    }
}
