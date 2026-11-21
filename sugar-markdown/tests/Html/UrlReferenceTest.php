<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests\Html;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Html\UrlReference;

/**
 * RFC 3986 section 5.4: the normal and abnormal reference examples, resolved
 * against the base http://a/b/c/d;p?q, with the fragment rule applied (none
 * of the RFC's examples carry a fragment that survives, so the expected
 * values are the RFC's own, minus any fragment).
 */
final class UrlReferenceTest extends TestCase
{
    private const BASE = 'http://a/b/c/d;p?q';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function rfcExamples(): iterable
    {
        yield 'scheme wins' => ['g:h', 'g:h'];
        yield 'sibling' => ['g', 'http://a/b/c/g'];
        yield 'dot slash' => ['./g', 'http://a/b/c/g'];
        yield 'directory' => ['g/', 'http://a/b/c/g/'];
        yield 'root path' => ['/g', 'http://a/g'];
        yield 'network path' => ['//g', 'http://g'];
        yield 'query only' => ['?y', 'http://a/b/c/d;p?y'];
        yield 'path and query' => ['g?y', 'http://a/b/c/g?y'];
        yield 'fragment only drops out' => ['#s', 'http://a/b/c/d;p?q'];
        yield 'path and fragment' => ['g#s', 'http://a/b/c/g'];
        yield 'path query and fragment' => ['g?y#s', 'http://a/b/c/g?y'];
        yield 'semicolon segment' => [';x', 'http://a/b/c/;x'];
        yield 'semicolon path' => ['g;x', 'http://a/b/c/g;x'];
        yield 'semicolon path query fragment' => ['g;x?y#s', 'http://a/b/c/g;x?y'];
        yield 'empty reference' => ['', 'http://a/b/c/d;p?q'];
        yield 'dot' => ['.', 'http://a/b/c/'];
        yield 'dot slash only' => ['./', 'http://a/b/c/'];
        yield 'dot dot' => ['..', 'http://a/b/'];
        yield 'dot dot slash' => ['../', 'http://a/b/'];
        yield 'dot dot sibling' => ['../g', 'http://a/b/g'];
        yield 'dot dot dot dot' => ['../..', 'http://a/'];
        yield 'dot dot dot dot slash' => ['../../', 'http://a/'];
        yield 'dot dot dot dot sibling' => ['../../g', 'http://a/g'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function abnormalExamples(): iterable
    {
        yield 'above the root' => ['../../../g', 'http://a/g'];
        yield 'far above the root' => ['../../../../g', 'http://a/g'];
        yield 'root dot segment' => ['/./g', 'http://a/g'];
        yield 'root dot dot segment' => ['/../g', 'http://a/g'];
        yield 'dot suffix is a name' => ['g.', 'http://a/b/c/g.'];
        yield 'dot prefix is a name' => ['.g', 'http://a/b/c/.g'];
        yield 'dot dot suffix is a name' => ['g..', 'http://a/b/c/g..'];
        yield 'dot dot prefix is a name' => ['..g', 'http://a/b/c/..g'];
        yield 'dot then dot dot' => ['./../g', 'http://a/b/g'];
        yield 'trailing dot segment' => ['./g/.', 'http://a/b/c/g/'];
        yield 'inner dot segment' => ['g/./h', 'http://a/b/c/g/h'];
        yield 'inner dot dot segment' => ['g/../h', 'http://a/b/c/h'];
        yield 'semicolon segment with dot' => ['g;x=1/./y', 'http://a/b/c/g;x=1/y'];
        yield 'semicolon segment with dot dot' => ['g;x=1/../y', 'http://a/b/c/y'];
        yield 'dot segments in query are kept' => ['g?y/./x', 'http://a/b/c/g?y/./x'];
        yield 'dot dot segments in query are kept' => ['g?y/../x', 'http://a/b/c/g?y/../x'];
        yield 'dot segments after fragment are dropped' => ['g#s/./x', 'http://a/b/c/g'];
        yield 'strict parser keeps a scheme-only reference' => ['http:g', 'http:g'];
    }

    /** @dataProvider rfcExamples */
    public function testNormalExamplesResolveAsTheRfcSays(string $reference, string $expected): void
    {
        $this->assertSame($expected, UrlReference::resolve(self::BASE, $reference));
    }

    /** @dataProvider abnormalExamples */
    public function testAbnormalExamplesResolveAsTheRfcSays(string $reference, string $expected): void
    {
        $this->assertSame($expected, UrlReference::resolve(self::BASE, $reference));
    }

    public function testAnAbsoluteReferenceIsNotRebased(): void
    {
        $this->assertSame('https://example.com/x?y', UrlReference::resolve(self::BASE, 'https://example.com/x?y'));
    }

    public function testAnAuthorityWithAnEmptyPathResolvesToRoot(): void
    {
        $this->assertSame('http://a/g', UrlReference::resolve('http://a', 'g'));
    }

    public function testTheResultIsNotVettedHere(): void
    {
        // Whatever scheme a reference names passes through: the caller's own
        // guard judges it, exactly as it would an absolute Location.
        $this->assertSame('file:x', UrlReference::resolve(self::BASE, 'file:x'));
        $this->assertSame('http://169.254.169.254/', UrlReference::resolve(self::BASE, '//169.254.169.254/'));
    }
}
