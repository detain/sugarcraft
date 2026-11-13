<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\PositiveInt;

/**
 * scriptui-29: the strict numeric door. A typo'd int-ish flag must
 * refuse loudly instead of casting into a different mode ((int)'abc'
 * === 0 reads as "unlimited"/"multi"/"auto" downstream).
 */
final class PositiveIntTest extends TestCase
{
    public function testDigitStringParses(): void
    {
        $this->assertSame(2, PositiveInt::parse('limit', '2'));
        $this->assertSame(0, PositiveInt::parse('width', '0'));
        $this->assertSame(12345, PositiveInt::parse('height', '12345'));
    }

    public function testNativeIntParses(): void
    {
        $this->assertSame(10, PositiveInt::parse('height', 10));
    }

    /**
     * @return array<array{0: mixed, 1: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'letters'        => ['abc', 'letters'],
            'word-number'    => ['two', 'word-number'],
            'negative'       => ['-5', 'negative'],
            'float'          => ['2.5', 'float'],
            'suffixed'       => ['2px', 'suffixed'],
            'empty'          => ['', 'empty'],
            'space-padded'   => [' 2', 'space-padded'],
            'plus-signed'    => ['+2', 'plus-signed'],
            'null'           => [null, 'null'],
            'bool-true'      => [true, 'bool-true'],
            'array'          => [['1'], 'array'],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testNonWholeNumbersRefuse(mixed $raw, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            PositiveInt::parse('limit', $raw);
        } catch (\InvalidArgumentException $e) {
            // The diagnostic names the flag so the operator sees which door refused.
            $this->assertStringContainsString('--limit', $e->getMessage());
            throw $e;
        }
        $this->fail("refusal expected for $label");
    }

    public function testNegativeIntNativeRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PositiveInt::parse('height', -1);
    }
}
