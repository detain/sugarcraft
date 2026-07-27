<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\WrapMode;
use PHPUnit\Framework\TestCase;

final class WrapModeTest extends TestCase
{
    public function testCasesReturnsThreeCases(): void
    {
        $this->assertCount(3, WrapMode::cases());
    }

    public function testNoneCaseExists(): void
    {
        $this->assertSame(WrapMode::None, WrapMode::None);
        $this->assertSame('None', WrapMode::None->name);
    }

    public function testWordWrapCaseExists(): void
    {
        $this->assertSame(WrapMode::WordWrap, WrapMode::WordWrap);
        $this->assertSame('WordWrap', WrapMode::WordWrap->name);
    }

    public function testCharacterCaseExists(): void
    {
        $this->assertSame(WrapMode::Character, WrapMode::Character);
        $this->assertSame('Character', WrapMode::Character->name);
    }

    public function testAllCasesHaveDistinctNames(): void
    {
        $names = array_map(fn(WrapMode $c) => $c->name, WrapMode::cases());
        $this->assertSame(['None', 'WordWrap', 'Character'], $names);
    }
}
