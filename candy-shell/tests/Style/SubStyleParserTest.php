<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Style;

use CandyCore\Shell\Style\SubStyleParser;
use CandyCore\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class SubStyleParserTest extends TestCase
{
    public function testEmptyArrayParsesToEmptyMap(): void
    {
        $this->assertSame([], SubStyleParser::parse([]));
    }

    public function testElementForegroundProducesStyledMap(): void
    {
        $map = SubStyleParser::parse(['cursor.foreground=4']);
        $this->assertArrayHasKey('cursor', $map);
        $this->assertInstanceOf(Style::class, $map['cursor']);
        // ANSI 4 = 34 in SGR.
        $this->assertStringContainsString("\x1b[34m", $map['cursor']->render('x'));
    }

    public function testMultipleFlagsForOneElementCompose(): void
    {
        $map = SubStyleParser::parse([
            'header.foreground=2',
            'header.bold=true',
        ]);
        $rendered = $map['header']->render('hi');
        $this->assertStringContainsString("\x1b[1m",  $rendered); // bold
        $this->assertStringContainsString("\x1b[32m", $rendered); // ANSI 2 → green
    }

    public function testGetFallsBackToGlobalStarThenEmpty(): void
    {
        $map = SubStyleParser::parse(['*.bold=true']);
        $cursor = SubStyleParser::get($map, 'cursor');
        // No explicit cursor entry → falls back to '*' which is bold.
        $this->assertStringContainsString("\x1b[1m", $cursor->render('x'));

        $emptyMap = [];
        $fallback = SubStyleParser::get($emptyMap, 'cursor');
        $this->assertSame('x', $fallback->render('x'));
    }

    public function testGlobalShorthandWithoutElementPrefix(): void
    {
        $map = SubStyleParser::parse(['bold=true']);
        $this->assertArrayHasKey('*', $map);
        $this->assertStringContainsString("\x1b[1m", $map['*']->render('x'));
    }

    public function testRejectsMalformedFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SubStyleParser::parse(['nokeyhere']);
    }

    public function testRejectsUnknownProp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SubStyleParser::parse(['cursor.foobar=1']);
    }

    public function testTruthyVariants(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on'] as $val) {
            $map = SubStyleParser::parse(["x.bold={$val}"]);
            $this->assertStringContainsString("\x1b[1m", $map['x']->render('y'),
                "expected truthy value '{$val}' to enable bold");
        }
        foreach (['false', '0', 'no', 'off', ''] as $val) {
            $map = SubStyleParser::parse(["x.bold={$val}"]);
            $this->assertStringNotContainsString("\x1b[1m", $map['x']->render('y'),
                "expected falsy value '{$val}' to leave bold off");
        }
    }
}
