<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ReservedSessionGrammar;

final class ReservedSessionGrammarTest extends TestCase
{
    public function testThePrefixIsAgentColon(): void
    {
        self::assertSame('agent:', ReservedSessionGrammar::PREFIX);
    }

    public function testIsReservedMatchesOnlyAPrefixCaseSensitively(): void
    {
        self::assertTrue(ReservedSessionGrammar::isReserved('agent:coder'));
        self::assertTrue(ReservedSessionGrammar::isReserved('agent:'));
        self::assertFalse(ReservedSessionGrammar::isReserved('my-agent:x'));
        self::assertFalse(ReservedSessionGrammar::isReserved('Agent:x'));
        self::assertFalse(ReservedSessionGrammar::isReserved('agent'));
        self::assertFalse(ReservedSessionGrammar::isReserved(''));
    }

    public function testRefusalMessageNamesThePrefix(): void
    {
        self::assertStringContainsString('"agent:"', ReservedSessionGrammar::refusalMessage());
    }

    public function testRefuseThrowsTheRefusalMessageForAReservedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(ReservedSessionGrammar::refusalMessage());

        ReservedSessionGrammar::refuse('agent:evil');
    }

    public function testRefuseAcceptsAnOrdinaryName(): void
    {
        ReservedSessionGrammar::refuse('daily-standup');

        $this->addToAssertionCount(1);
    }
}
