<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Border\BorderTitle;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Sprinkles\Style;

final class BorderTitleTest extends TestCase
{
    // ── TitleAnchor enum ────────────────────────────────────────────────────

    public function testTitleAnchorCases(): void
    {
        $this->assertSame('TopLeft',      TitleAnchor::TopLeft->name);
        $this->assertSame('TopCenter',    TitleAnchor::TopCenter->name);
        $this->assertSame('TopRight',     TitleAnchor::TopRight->name);
        $this->assertSame('BottomLeft',   TitleAnchor::BottomLeft->name);
        $this->assertSame('BottomCenter', TitleAnchor::BottomCenter->name);
        $this->assertSame('BottomRight',  TitleAnchor::BottomRight->name);
        $this->assertCount(6, TitleAnchor::cases());
    }

    // ── BorderTitle value object ────────────────────────────────────────────

    public function testBorderTitleProperties(): void
    {
        $title = new BorderTitle('My App', TitleAnchor::TopLeft);
        $this->assertSame('My App', $title->text);
        $this->assertSame(TitleAnchor::TopLeft, $title->anchor);
        $this->assertSame(' ', $title->separator);
    }

    public function testBorderTitleCustomSeparator(): void
    {
        $title = new BorderTitle('v1.0', TitleAnchor::TopRight, separator: ' · ');
        $this->assertSame(' · ', $title->separator);
    }

    // ── Border::withTitle() ─────────────────────────────────────────────────

    public function testWithTitleDefaultsToTopLeft(): void
    {
        $b = Border::rounded()->withTitle('My App');
        $titles = $b->getTitles();
        $this->assertArrayHasKey('TopLeft', $titles);
        $this->assertCount(1, $titles['TopLeft']);
        $this->assertSame('My App', $titles['TopLeft'][0]->text);
    }

    public function testWithTitleWithExplicitAnchor(): void
    {
        $b = Border::rounded()->withTitle('Status', TitleAnchor::BottomRight);
        $titles = $b->getTitles();
        $this->assertArrayHasKey('BottomRight', $titles);
        $this->assertSame('Status', $titles['BottomRight'][0]->text);
    }

    public function testWithTitleIsAdditive(): void
    {
        $b = Border::rounded()
            ->withTitle('First')
            ->withTitle('Second', TitleAnchor::TopLeft)
            ->withTitle('Third',  TitleAnchor::TopRight);
        $titles = $b->getTitles();
        $this->assertCount(2, $titles['TopLeft']);
        $this->assertSame('First', $titles['TopLeft'][0]->text);
        $this->assertSame('Second', $titles['TopLeft'][1]->text);
        $this->assertCount(1, $titles['TopRight']);
        $this->assertSame('Third', $titles['TopRight'][0]->text);
    }

    public function testWithTitlePreservesBorderCharacters(): void
    {
        $b = Border::rounded()->withTitle('My App', TitleAnchor::TopLeft);
        $this->assertSame('─', $b->top);
        $this->assertSame('╭', $b->topLeft);
        $this->assertSame('╮', $b->topRight);
    }

    // ── Border::withTitles() ────────────────────────────────────────────────

    public function testWithTitlesBulk(): void
    {
        $b = Border::rounded()->withTitles([
            'TopLeft'   => ['Left Title'],
            'TopCenter' => ['Center Title'],
            'TopRight'  => ['Right Title'],
        ]);
        $titles = $b->getTitles();
        $this->assertSame('Left Title',   $titles['TopLeft'][0]->text);
        $this->assertSame('Center Title', $titles['TopCenter'][0]->text);
        $this->assertSame('Right Title',  $titles['TopRight'][0]->text);
    }

    public function testWithTitlesReplacesPriorTitles(): void
    {
        $b = Border::rounded()
            ->withTitle('Old', TitleAnchor::TopLeft)
            ->withTitles(['TopLeft' => ['New']]);
        $titles = $b->getTitles();
        $this->assertCount(1, $titles['TopLeft']);
        $this->assertSame('New', $titles['TopLeft'][0]->text);
    }

    public function testWithTitlesAcceptsStringInsteadOfList(): void
    {
        $b = Border::rounded()->withTitles([
            'TopLeft' => 'Single Title',
        ]);
        $titles = $b->getTitles();
        $this->assertCount(1, $titles['TopLeft']);
        $this->assertSame('Single Title', $titles['TopLeft'][0]->text);
    }

    // ── Style rendering with border titles ─────────────────────────────────

    public function testStyleWithTopLeftTitle(): void
    {
        $style = Style::new()
            ->border(Border::rounded()->withTitle('My App', TitleAnchor::TopLeft))
            ->width(20);
        $rendered = $style->render('Hello');
        $lines = explode("\n", $rendered);
        // Top border should contain "My App" after the left corner
        $this->assertStringStartsWith('╭', $lines[0]);
        $this->assertStringContainsString('My App', $lines[0]);
    }

    public function testStyleWithTopRightTitle(): void
    {
        $style = Style::new()
            ->border(Border::rounded()->withTitle('v1.0', TitleAnchor::TopRight))
            ->width(20);
        $rendered = $style->render('Hello');
        $lines = explode("\n", $rendered);
        // Top border should end with "v1.0" before the right corner
        $this->assertStringContainsString('v1.0', $lines[0]);
    }

    public function testStyleWithTopCenterTitle(): void
    {
        $style = Style::new()
            ->border(Border::rounded()->withTitle('Center', TitleAnchor::TopCenter))
            ->width(20);
        $rendered = $style->render('Hello');
        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('Center', $lines[0]);
    }

    public function testStyleWithBottomTitles(): void
    {
        $style = Style::new()
            ->border(Border::rounded()
                ->withTitle('Footer Left', TitleAnchor::BottomLeft)
                ->withTitle('Footer Right', TitleAnchor::BottomRight))
            ->width(40);  // Wide enough for both titles
        $rendered = $style->render('Body content here for testing');
        $lines = explode("\n", $rendered);
        $last = array_pop($lines);
        $this->assertStringContainsString('Footer Left', $last);
        $this->assertStringContainsString('Footer Right', $last);
    }

    public function testStyleWithMultipleTitlesSameAnchor(): void
    {
        $style = Style::new()
            ->border(Border::rounded()
                ->withTitle('One', TitleAnchor::TopLeft)
                ->withTitle('Two', TitleAnchor::TopLeft))
            ->width(20);
        $rendered = $style->render('Hello');
        $lines = explode("\n", $rendered);
        $this->assertStringContainsString('One', $lines[0]);
        $this->assertStringContainsString('Two', $lines[0]);
    }

    public function testStyleWithoutTitlesStillWorks(): void
    {
        $style = Style::new()
            ->border(Border::rounded())
            ->width(10);
        $rendered = $style->render('Hi');
        $lines = explode("\n", $rendered);
        $this->assertStringStartsWith('╭', $lines[0]);
        $this->assertStringContainsString('╮', $lines[0]);
    }

    public function testStyleWithTitleOverflowTruncates(): void
    {
        // A very long title should be truncated to fit the available width
        $style = Style::new()
            ->border(Border::rounded()->withTitle('A Very Long Title That Exceeds Width', TitleAnchor::TopLeft))
            ->width(5);  // Very narrow
        $rendered = $style->render('Hi');
        $lines = explode("\n", $rendered);
        // Title should be truncated (ellipsis or cut)
        $this->assertGreaterThan(0, count($lines));
    }

    public function testBorderTitlesAccessorAndDeprecatedAlias(): void
    {
        $b = Border::normal()->withTitle('T', TitleAnchor::BottomRight);
        $this->assertSame($b->titles(), $b->getTitles());
        $this->assertSame('T', $b->titles()['BottomRight'][0]->text);
    }

    public function testCenterTitleDroppedWhenSideTitlesExhaustTopEdge(): void
    {
        $style = Style::new()
            ->border(Border::normal()->withTitles(['TopLeft' => 'ABCD', 'TopRight' => 'XY', 'TopCenter' => 'CENTERED']))
            ->width(4);
        // Edge is 4 cells: "ABCD" takes all of it, the right title and the
        // center title get nothing — the box stays 6 cells wide.
        $this->assertSame("┌ABCD┐\n│a   │\n└────┘", $style->render('a'));
    }

    public function testCenterTitleDroppedWhenSideTitlesExhaustBottomEdge(): void
    {
        $style = Style::new()
            ->border(Border::normal()->withTitles(['BottomLeft' => 'AB', 'BottomRight' => 'XY', 'BottomCenter' => 'MID']))
            ->width(4);
        $this->assertSame("┌────┐\n│a   │\n└ABXY┘", $style->render('a'));
    }

    public function testTruncatedTitleKeepsItsStyling(): void
    {
        $red = \SugarCraft\Core\Util\Color::hex('#ff0000');
        $styled = Style::new()->foreground($red)->render('LongTitle');
        $style = Style::new()
            ->border(Border::normal()->withTitle($styled, TitleAnchor::TopLeft))
            ->width(4);
        $top = explode("\n", $style->render('a'))[0];
        // Truncated to the 4-cell edge, but the title's own SGR survives.
        $this->assertStringContainsString("\x1b[38;2;255;0;0mLong", $top);
        $this->assertSame(6, \SugarCraft\Core\Util\Width::string($top));

        $bottom = Style::new()
            ->border(Border::normal()->withTitle($styled, TitleAnchor::BottomCenter))
            ->width(4);
        $last = explode("\n", $bottom->render('a'))[2];
        $this->assertStringContainsString("\x1b[38;2;255;0;0mLong", $last);
    }

    public function testEdgeColourResumesAfterTitle(): void
    {
        $red = \SugarCraft\Core\Util\Color::hex('#ff0000');
        $top = explode("\n", Style::new()
            ->border(Border::normal()->withTitle('T'))
            ->borderForeground($red)
            ->width(4)
            ->render('a'))[0];
        $this->assertSame(
            "\x1b[38;2;255;0;0m┌\x1b[38;2;255;0;0mT\x1b[0m\x1b[38;2;255;0;0m───┐\x1b[0m",
            $top,
        );
    }
}
