<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{NavStack, NavigationItem, Url};
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
}
