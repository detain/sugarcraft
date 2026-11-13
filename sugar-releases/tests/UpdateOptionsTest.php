<?php

declare(strict_types=1);

namespace SugarCraft\Releases\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Releases\UpdateOptions;

final class UpdateOptionsTest extends TestCase
{
    public function testDefaultsAreRunnable(): void
    {
        $o = UpdateOptions::new();
        self::assertFalse($o->check);
        self::assertFalse($o->rollback);
        self::assertFalse($o->allowUnsigned);
        self::assertNull($o->channel);
        self::assertSame([], $o->operands);
        self::assertNull($o->validate());
    }

    public function testValidateReportsTheFirstViolationInTheVerbsOrder(): void
    {
        self::assertSame(['selfupdate.flags_exclusive', []], UpdateOptions::new()->withCheck()->withRollback()->validate());
        self::assertSame(['selfupdate.flags_exclusive', []], UpdateOptions::new()->withCheck()->withAllowUnsigned()->validate());
        self::assertSame(['selfupdate.channel_invalid', ['value' => 'Dev']], UpdateOptions::new()->withChannel('Dev')->validate());
        self::assertSame(['selfupdate.unexpected_operand', ['operand' => 'x']], UpdateOptions::new()->withOperands(['x', 'y'])->validate());
        self::assertNull(UpdateOptions::new()->withChannel('latest')->withCheck()->validate());
        self::assertNull(UpdateOptions::new()->withChannel(UpdateOptions::CHANNEL_STABLE)->withRollback()->validate());
    }

    public function testWithersAreImmutable(): void
    {
        $a = UpdateOptions::new();
        $b = $a->withCheck()->withRollback(false)->withAllowUnsigned()->withChannel('latest')->withOperands(['q']);
        self::assertFalse($a->check);
        self::assertTrue($b->check);
        self::assertTrue($b->allowUnsigned);
        self::assertSame('latest', $b->channel);
        self::assertSame(['q'], $b->operands);
    }
}
