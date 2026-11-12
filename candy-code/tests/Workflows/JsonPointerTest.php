<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\JsonPointer;

/**
 * P1-5: the RFC 6901 resolver the condition grammar reads typed rows with.
 * Its semantics are pinned to the engine's `{{name.<pointer>}}` helper
 * (leading slash optional, split-then-unescape, ~1 before ~0) so the two
 * surfaces answer the same leaf for the same spelling.
 */
final class JsonPointerTest extends TestCase
{
    private const DOC = [
        'verdict' => 'PASS',
        'items' => [['id' => 'a'], ['id' => 'b']],
        'a/b' => 'slash',
        'm~n' => 'tilde',
        '~1' => 'literal-tilde-one',
        'a.b' => 'dotted',
        'nothing' => null,
        'zero' => 0,
        'flag' => false,
        'empty' => [],
        '' => 'blank-key',
    ];

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function hits(): iterable
    {
        yield 'rooted member' => ['/verdict', 'PASS'];
        yield 'bare member (engine parity)' => ['verdict', 'PASS'];
        yield 'array index' => ['/items/1/id', 'b'];
        yield 'bare array index' => ['items/0/id', 'a'];
        yield '~1 unescapes to slash' => ['/a~1b', 'slash'];
        yield '~0 unescapes to tilde' => ['/m~0n', 'tilde'];
        yield '~01 is ~1 literally (order ~1 then ~0)' => ['/~01', 'literal-tilde-one'];
        yield 'dot is part of a key' => ['/a.b', 'dotted'];
        yield 'json null is FOUND' => ['/nothing', null];
        yield 'falsy zero' => ['/zero', 0];
        yield 'falsy bool' => ['/flag', false];
        yield 'empty array' => ['/empty', []];
        yield 'interior array' => ['/items/0', ['id' => 'a']];
        yield 'slash alone is the empty key' => ['/', 'blank-key'];
    }

    #[DataProvider('hits')]
    public function testResolveFinds(string $pointer, mixed $expected): void
    {
        $this->assertSame(['found' => true, 'value' => $expected], JsonPointer::resolve(self::DOC, $pointer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function misses(): iterable
    {
        yield 'missing member' => ['/ghost'];
        yield 'index past the end' => ['/items/9'];
        yield 'non-canonical index' => ['/items/01'];
        yield 'append marker reaches nothing' => ['/items/-'];
        yield 'through a scalar' => ['/verdict/x'];
        yield 'through a null' => ['/nothing/x'];
        yield 'unescaped slash splits' => ['/m~n/x'];
    }

    #[DataProvider('misses')]
    public function testResolveMisses(string $pointer): void
    {
        $this->assertSame(['found' => false, 'value' => null], JsonPointer::resolve(self::DOC, $pointer));
    }

    public function testTheEmptyPointerIsTheWholeDocument(): void
    {
        $this->assertSame(['found' => true, 'value' => self::DOC], JsonPointer::resolve(self::DOC, ''));
        $this->assertSame(['found' => true, 'value' => 'x'], JsonPointer::resolve('x', ''));
    }

    public function testResolveJsonDecodesThenResolves(): void
    {
        $json = '{"review":{"verdict":"NEEDS_FIX","notes":null}}';

        $this->assertSame(['found' => true, 'value' => 'NEEDS_FIX'], JsonPointer::resolveJson($json, '/review/verdict'));
        $this->assertSame(['found' => true, 'value' => null], JsonPointer::resolveJson($json, '/review/notes'));
        $this->assertSame(['found' => false, 'value' => null], JsonPointer::resolveJson($json, '/review/ghost'));
    }

    public function testResolveJsonOnInvalidJsonReachesNothing(): void
    {
        // A literal `null` document is valid JSON and resolvable; garbage is not.
        $this->assertSame(['found' => false, 'value' => null], JsonPointer::resolveJson('{not json', ''));
        $this->assertSame(['found' => false, 'value' => null], JsonPointer::resolveJson('', '/a'));
        $this->assertSame(['found' => true, 'value' => null], JsonPointer::resolveJson('null', ''));
    }

    public function testTokensSplitBeforeUnescaping(): void
    {
        $this->assertSame([], JsonPointer::tokens(''));
        $this->assertSame([''], JsonPointer::tokens('/'));
        $this->assertSame(['a/b', 'm~n', '0'], JsonPointer::tokens('/a~1b/m~0n/0'));
        $this->assertSame(['a', 'b'], JsonPointer::tokens('a/b'));
    }

    public function testIsWellFormedRefusesOnlyIllegalEscapes(): void
    {
        $this->assertTrue(JsonPointer::isWellFormed('/a~0b/c~1d'));
        $this->assertTrue(JsonPointer::isWellFormed('bare/form'));
        $this->assertTrue(JsonPointer::isWellFormed(''));
        $this->assertFalse(JsonPointer::isWellFormed('/a~2'));
        $this->assertFalse(JsonPointer::isWellFormed('/trailing~'));
    }
}
