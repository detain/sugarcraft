<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\AutoSuggest;

final class AutoSuggestTest extends TestCase
{
    public function testNoneReturnsEmptySuggestion(): void
    {
        $suggest = AutoSuggest::none();
        $this->assertSame('', $suggest->suggestion());
        $this->assertFalse($suggest->isFromHistory());
    }

    public function testFromHistoryReturnsSuggestion(): void
    {
        $suggest = AutoSuggest::fromHistory('456');
        $this->assertSame('456', $suggest->suggestion());
        $this->assertTrue($suggest->isFromHistory());
    }

    public function testFromHistoryWithEmptyString(): void
    {
        $suggest = AutoSuggest::fromHistory('');
        $this->assertSame('', $suggest->suggestion());
        $this->assertTrue($suggest->isFromHistory());
    }

    public function testSuggestionIsReadonly(): void
    {
        $suggest = AutoSuggest::fromHistory('abc');
        $this->assertSame('abc', $suggest->suggestion());
    }
}
