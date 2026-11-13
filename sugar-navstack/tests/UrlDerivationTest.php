<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use SugarCraft\NavStack\{NavStack, NavigationItem, Url};
use PHPUnit\Framework\TestCase;

final class UrlDerivationTest extends TestCase
{
    public function testDeriveEmptyStack(): void
    {
        $stack = new NavStack();
        $this->assertSame('/', Url::derive($stack));
    }

    public function testDeriveSingleItem(): void
    {
        $stack = new NavStack();
        $stack->push('home');
        $this->assertSame('/home', Url::derive($stack));
    }

    public function testDeriveMultipleItems(): void
    {
        $stack = new NavStack();
        $stack->push('home');
        $stack->push('settings');
        $stack->push('display');
        $this->assertSame('/home/settings/display', Url::derive($stack));
    }

    public function testDeriveEncodesSpecialCharacters(): void
    {
        $stack = new NavStack();
        $stack->push('home');
        $stack->push('my settings');
        $this->assertSame('/home/my%20settings', Url::derive($stack));
    }

    public function testDeriveEncodesPathSegments(): void
    {
        $stack = new NavStack();
        $stack->push('home');
        $stack->push('user/profile');
        $this->assertSame('/home/user%2Fprofile', Url::derive($stack));
    }

    public function testParseEmptyPath(): void
    {
        $stack = Url::parse('/');
        $this->assertSame(0, $stack->depth());
    }

    public function testParseSingleSegment(): void
    {
        $stack = Url::parse('/home');
        $this->assertSame(1, $stack->depth());
        $this->assertSame('home', $stack->current()->title);
    }

    public function testParseMultipleSegments(): void
    {
        $stack = Url::parse('/home/settings/display');
        $this->assertSame(3, $stack->depth());
        $this->assertSame('home', $stack->items()[0]->title);
        $this->assertSame('settings', $stack->items()[1]->title);
        $this->assertSame('display', $stack->items()[2]->title);
    }

    public function testParseDecodesSpecialCharacters(): void
    {
        $stack = Url::parse('/home/my%20settings');
        $this->assertSame('my settings', $stack->items()[1]->title);
    }

    public function testParseHandlesTrailingSlash(): void
    {
        $stack = Url::parse('/home/settings/');
        $this->assertSame(2, $stack->depth());
        $this->assertSame('settings', $stack->current()->title);
    }

    public function testParseHandlesNoLeadingSlash(): void
    {
        $stack = Url::parse('home/settings');
        $this->assertSame(2, $stack->depth());
        $this->assertSame('home', $stack->items()[0]->title);
    }

    public function testRoundTripDeriveThenParse(): void
    {
        $original = new NavStack();
        $original->push('home');
        $original->push('my settings');
        $original->push('display');

        $derived = Url::derive($original);
        $restored = Url::parse($derived);

        $this->assertSame($original->depth(), $restored->depth());
        for ($i = 0; $i < $original->depth(); $i++) {
            $this->assertSame(
                $original->items()[$i]->title,
                $restored->items()[$i]->title
            );
        }
    }

    public function testRoundTripParseThenDerive(): void
    {
        $original = '/home/my%20settings/display';

        $parsed = Url::parse($original);
        $derived = Url::derive($parsed);

        $this->assertSame($original, $derived);
    }

    public function testParseIgnoresEmptySegments(): void
    {
        $stack = Url::parse('//home///settings//');
        $this->assertSame(2, $stack->depth());
        $this->assertSame('home', $stack->items()[0]->title);
        $this->assertSame('settings', $stack->items()[1]->title);
    }

    // ─── NS-22: sanitize-then-encode (URL carries what the user SEES) ────────

    public function testDeriveCarriesNoPercentEncodedTerminalJunk(): void
    {
        $stack = new NavStack();
        $stack->push("Ge\x1b[31mne\u{E000}ral");

        // Old shape encoded the RAW title: /Ge%1B%5B31mne%EE%80%80ral —
        // percent-reanimatable bytes travelling in a path. Post-NS-22 the
        // sweep runs first, so the path is the DISPLAYED word:
        $this->assertSame('/General', Url::derive($stack));
        $this->assertStringNotContainsString('%1B', Url::derive($stack));
    }

    public function testDeriveEncodesPercentLiteralsOfSanitizedTitles(): void
    {
        $stack = new NavStack();
        $stack->push('100%zz');

        // '%zz' is an INVALID percent sequence and must arrive as the
        // literal text, via %25 — never a raw '%' segment that a decoder
        // would trip over:
        $this->assertSame('/100%25zz', Url::derive($stack));
        $this->assertSame('100%zz', Url::parse(Url::derive($stack))->items()[0]->title);
    }

    public function testMultibyteTitlesTravelAsPercentExilesByteExactly(): void
    {
        $stack = new NavStack();
        $stack->push('Café');

        // rawurlencode over UTF-8 bytes (RFC 3986 §2.4): the é ships as the
        // two percent-exiled bytes and restores byte-identically.
        $this->assertSame('/Caf%C3%A9', Url::derive($stack));
        $this->assertSame('Café', Url::parse('/Caf%C3%A9')->items()[0]->title);
    }

    public function testParsedTitlesEqualTheDisplaySweepOfDerivedTitles(): void
    {
        // The round-trip contract, executably: what parse() re-stores is
        // byte-identical to what every render surface would DISPLAY of the
        // original — the URL is a faithful carrier of the visible title.
        $stack = new NavStack();
        foreach (["Ho\x1b[31mme", "Set\u{200E}tings-x", '100%é', 'Ünïcode ✓'] as $t) {
            $stack->push($t);
        }

        $titles = array_map(
            static fn (NavigationItem $i): string => NavStack::safeTitle($i->title),
            $stack->items()
        );
        $back = Url::parse(Url::derive($stack));
        $this->assertSame($titles, array_map(static fn (NavigationItem $i): string => $i->title, $back->items()));
    }

    public function testDeriveToIsASegmentSliceOfDeriveByteForByte(): void
    {
        $stack = NavStack::new();
        $stack->push('Home')->push('My Settings')->push('Café')->push('100%zz');

        $segments = \explode('/', Url::derive($stack)); // ['', 'Home', ...] — rawurlencode keeps '/' encoded inside segments
        for ($i = 0, $n = \count($stack->items()); $i < $n; $i++) {
            $expected = '/' . \implode('/', \array_slice($segments, 1, $i + 1));
            $this->assertSame($expected, Url::deriveTo($stack, $i));
        }
    }

    public function testDeriveToExactBytes(): void
    {
        $stack = NavStack::new();
        $stack->push('Home')->push('Settings');

        $this->assertSame('/Home', Url::deriveTo($stack, 0));
        $this->assertSame('/Home/Settings', Url::deriveTo($stack, 1));
    }

    public function testDeriveToDuplicateTitlesYieldDistinctPrefixes(): void
    {
        $stack = NavStack::new();
        $stack->push('Home')->push('Settings')->push('Settings');

        $this->assertSame('/Home/Settings', Url::deriveTo($stack, 1));
        $this->assertSame('/Home/Settings/Settings', Url::deriveTo($stack, 2));
    }

    public function testDeriveToRefusesIndicesOutsideTheStack(): void
    {
        $stack = NavStack::new();
        $stack->push('Home');

        foreach ([-1, 1] as $bad) {
            try {
                Url::deriveTo($stack, $bad);
                $this->fail('deriveTo() accepted out-of-stack index ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('deriveTo() index ' . $bad, $e->getMessage());
            }
        }
    }

    public function testDeriveToRefusesEmptyStack(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deriveTo() index 0 is outside the stack of 0 items');
        Url::deriveTo(NavStack::new(), 0);
    }
}
