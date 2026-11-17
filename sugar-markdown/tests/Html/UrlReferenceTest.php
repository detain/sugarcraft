<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Html;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Html\UrlReference;

/**
 * RFC 3986 §5.4 reference resolution. The fragment is dropped on purpose:
 * it never changes what a server returns.
 */
final class UrlReferenceTest extends TestCase
{
    private const BASE = 'http://a/b/c/d;p?q';

    /** @return iterable<string, array{string, string}> */
    public static function rfcExamples(): iterable
    {
        yield 'scheme' => ['g:h', 'g:h'];
        yield 'relative' => ['g', 'http://a/b/c/g'];
        yield 'dot' => ['./g', 'http://a/b/c/g'];
        yield 'trailing slash' => ['g/', 'http://a/b/c/g/'];
        yield 'absolute path' => ['/g', 'http://a/g'];
        yield 'network path' => ['//g', 'http://g'];
        yield 'query only' => ['?y', 'http://a/b/c/d;p?y'];
        yield 'path and query' => ['g?y', 'http://a/b/c/g?y'];
        yield 'fragment only' => ['#s', 'http://a/b/c/d;p?q'];
        yield 'parent' => ['../g', 'http://a/b/g'];
        yield 'two parents' => ['../../g', 'http://a/g'];
        yield 'too many parents' => ['../../../g', 'http://a/g'];
        yield 'parameter segment' => ['g;x=1/../y', 'http://a/b/c/y'];
        yield 'empty reference' => ['', 'http://a/b/c/d;p?q'];
    }

    /** @dataProvider rfcExamples */
    public function testResolvesRfc3986Examples(string $reference, string $expected): void
    {
        $this->assertSame($expected, UrlReference::resolve(self::BASE, $reference));
    }

    public function testFragmentIsDroppedFromAnAbsoluteReference(): void
    {
        $this->assertSame('https://x.test/p?q', UrlReference::resolve(self::BASE, 'https://x.test/p?q#frag'));
    }

    public function testAuthorityWithEmptyPathGetsASlashBeforeARelativeReference(): void
    {
        $this->assertSame('http://host/x', UrlReference::resolve('http://host', 'x'));
    }
}
