<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * Pins for the constructor-derived length cache (E736 plan 8.5): length()
 * must keep reporting mb_strlen semantics for the CURRENT value on every
 * snapshot, no matter which mutation path built it.
 */
final class TextInputLengthCacheTest extends TestCase
{
    public function testEmptyBufferHasZeroLength(): void
    {
        $this->assertSame(0, TextInput::new()->length());
    }

    public function testLengthCountsCodepointsNotBytes(): void
    {
        // 'aé中' is 3 codepoints / 6 UTF-8 bytes.
        $t = TextInput::new()->setValue('aé中');
        $this->assertSame(3, $t->length());
        $this->assertSame(3, mb_strlen($t->value, 'UTF-8'));
    }

    public function testCacheTracksEveryValuePath(): void
    {
        $t = TextInput::new();
        $t = $t->setValue('héllo');
        $this->assertSame(5, $t->length());
        $t = $t->setValue('日本語');
        $this->assertSame(3, $t->length());
        $t = $t->setValue('');
        $this->assertSame(0, $t->length());
    }

    public function testLengthStaysInSyncAfterPlaceholderOnlyMutation(): void
    {
        // A mutate that does NOT touch value must still carry the right
        // length through the constructor — the cache is derived, never
        // threaded by callers.
        $t = TextInput::new()->setValue('abc')->withPlaceholder('p');
        $this->assertSame(3, $t->length());
    }

    public function testCacheIsPrivateReadonly(): void
    {
        $prop = new ReflectionProperty(TextInput::class, 'length');
        $this->assertTrue($prop->isPrivate());
        $this->assertTrue($prop->isReadOnly());
    }
}
