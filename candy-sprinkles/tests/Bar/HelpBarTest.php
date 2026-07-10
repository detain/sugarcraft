<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Bar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Bar\HelpBar;
use SugarCraft\Sprinkles\Style;

final class HelpBarTest extends TestCase
{
    // ─── Factory + defaults ─────────────────────────────────────────────

    public function testNewDefaults(): void
    {
        $h = HelpBar::new();
        $this->assertSame([], $h->entries());
        $this->assertSame(': ', $h->getKeySeparator());
        $this->assertSame(' │ ', $h->getSeparator());
        $this->assertNull($h->getKeyStyle());
        $this->assertNull($h->getLabelStyle());
        $this->assertNull($h->getWidth());
        $this->assertTrue($h->isVisible());
        $this->assertSame('', $h->render());
    }

    public function testFromMapBuildsOrderedEntries(): void
    {
        $h = HelpBar::fromMap(['j' => 'down', 'k' => 'up']);
        $this->assertSame(
            [['key' => 'j', 'label' => 'down'], ['key' => 'k', 'label' => 'up']],
            $h->entries(),
        );
    }

    // ─── Entry builders ─────────────────────────────────────────────────

    public function testAddAppendsAndKeepsDuplicates(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->add('q', 'again');
        $this->assertCount(2, $h->entries());
        $this->assertSame('again', $h->entries()[1]['label']);
    }

    public function testBindingsReplacesEntries(): void
    {
        $h = HelpBar::new()->add('x', 'y')->bindings(['a' => 'alpha']);
        $this->assertSame([['key' => 'a', 'label' => 'alpha']], $h->entries());
    }

    public function testWithoutDropsFirstMatchingKey(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->add('?', 'help')->without('q');
        $this->assertSame([['key' => '?', 'label' => 'help']], $h->entries());
    }

    public function testWithoutNoMatchIsNoOp(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->without('zzz');
        $this->assertCount(1, $h->entries());
    }

    // ─── Rendering ──────────────────────────────────────────────────────

    public function testRenderDefaultFormat(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->add('?', 'help');
        $this->assertSame('q: quit │ ?: help', $h->render());
    }

    public function testRenderCustomSeparators(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->add('w', 'write')
            ->keySeparator('=')->separator('  ');
        $this->assertSame('q=quit  w=write', $h->render());
    }

    public function testRenderKeyStyleBytes(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->keyStyle(Style::new()->bold());
        $this->assertSame("\x1b[1mq\x1b[0m: quit", $h->render());
    }

    public function testRenderLabelStyleBytes(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->labelStyle(Style::new()->bold());
        $this->assertSame("q: \x1b[1mquit\x1b[0m", $h->render());
    }

    public function testRenderWidthTruncates(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->add('?', 'help')->width(6);
        $this->assertSame('q: qui', $h->render());
        $this->assertSame(6, Width::string($h->render()));
    }

    public function testRenderWidthNoTruncateWhenFits(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->width(100);
        $this->assertSame('q: quit', $h->render());
    }

    public function testHiddenRendersEmpty(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->hidden();
        $this->assertFalse($h->isVisible());
        $this->assertSame('', $h->render());
    }

    public function testVisibleTrueRenders(): void
    {
        $h = HelpBar::new()->add('q', 'quit')->hidden()->visible();
        $this->assertSame('q: quit', $h->render());
    }

    public function testEmptyEntriesRenderEmpty(): void
    {
        $this->assertSame('', HelpBar::new()->render());
    }

    public function testToStringMatchesRender(): void
    {
        $h = HelpBar::new()->add('q', 'quit');
        $this->assertSame($h->render(), (string) $h);
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    public function testKeySeparatorAccessor(): void
    {
        $this->assertSame(' -> ', HelpBar::new()->keySeparator(' -> ')->getKeySeparator());
    }

    public function testSeparatorAccessor(): void
    {
        $this->assertSame(' | ', HelpBar::new()->separator(' | ')->getSeparator());
    }

    public function testKeyStyleAccessor(): void
    {
        $style = Style::new()->bold();
        $this->assertSame($style, HelpBar::new()->keyStyle($style)->getKeyStyle());
    }

    public function testLabelStyleAccessor(): void
    {
        $style = Style::new()->italic();
        $this->assertSame($style, HelpBar::new()->labelStyle($style)->getLabelStyle());
    }

    public function testWidthAccessor(): void
    {
        $this->assertSame(30, HelpBar::new()->width(30)->getWidth());
    }

    public function testWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HelpBar::new()->width(-5);
    }

    // ─── Immutability ───────────────────────────────────────────────────

    public function testSettersDoNotMutateSource(): void
    {
        $base = HelpBar::new()->add('q', 'quit');
        $derived = $base->add('?', 'help')->keySeparator('=')->separator('  ')
            ->keyStyle(Style::new()->bold())->labelStyle(Style::new()->italic())
            ->width(10)->hidden();
        $this->assertNotSame($base, $derived);
        $this->assertCount(1, $base->entries());
        $this->assertSame(': ', $base->getKeySeparator());
        $this->assertSame(' │ ', $base->getSeparator());
        $this->assertNull($base->getKeyStyle());
        $this->assertNull($base->getLabelStyle());
        $this->assertNull($base->getWidth());
        $this->assertTrue($base->isVisible());
    }
}
