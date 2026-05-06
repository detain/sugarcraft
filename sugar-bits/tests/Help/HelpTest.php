<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Help;

use CandyCore\Bits\Help\Help;
use CandyCore\Bits\Help\Styles;
use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\KeyMap;
use CandyCore\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class FakeKeyMap implements KeyMap
{
    /** @param list<Binding> $short @param list<list<Binding>> $full */
    public function __construct(
        private readonly array $short,
        private readonly array $full,
    ) {}

    public function shortHelp(): array { return $this->short; }
    public function fullHelp(): array  { return $this->full; }
}

final class HelpTest extends TestCase
{
    private function b(string $key, string $desc, array $keys = []): Binding
    {
        return (new Binding($keys ?: [$key]))->withHelp($key, $desc);
    }

    public function testShortViewSeparates(): void
    {
        $map = new FakeKeyMap([
            $this->b('↑/k', 'up'),
            $this->b('↓/j', 'down'),
            $this->b('q',   'quit'),
        ], []);
        $this->assertSame('↑/k up • ↓/j down • q quit', (new Help())->shortView($map));
    }

    public function testShortViewSkipsDisabledAndUnlabeled(): void
    {
        $map = new FakeKeyMap([
            $this->b('q', 'quit'),
            (new Binding(['x']))->withHelp('x', 'cut')->disable(),
            new Binding(['z']), // no help labels
        ], []);
        $this->assertSame('q quit', (new Help())->shortView($map));
    }

    public function testShortViewCustomSeparator(): void
    {
        $map = new FakeKeyMap([
            $this->b('a', 'alpha'),
            $this->b('b', 'beta'),
        ], []);
        $help = (new Help())->withSeparator(' | ');
        $this->assertSame('a alpha | b beta', $help->shortView($map));
    }

    public function testFullViewAlignsColumns(): void
    {
        $map = new FakeKeyMap([], [
            [$this->b('↑/k', 'up'),    $this->b('↓/j', 'down')],
            [$this->b('q',   'quit'),  $this->b('?',   'help')],
        ]);
        $out = (new Help())->fullView($map);
        $expected =
            "↑/k up      q quit\n"
          . "↓/j down    ? help";
        $this->assertSame($expected, $out);
    }

    public function testFullViewEmptyKeyMap(): void
    {
        $map = new FakeKeyMap([], []);
        $this->assertSame('', (new Help())->fullView($map));
    }

    public function testViewDispatchesByShowAll(): void
    {
        $map = new FakeKeyMap([
            $this->b('q', 'quit'),
        ], [
            [$this->b('q', 'quit')],
        ]);
        $shortFirst = new Help();
        $this->assertSame('q quit', $shortFirst->view($map));
        $this->assertSame('q quit', $shortFirst->showAll(true)->view($map));
        // Original instance unchanged.
        $this->assertSame('q quit', $shortFirst->view($map));
    }

    public function testWidthTruncatesWithEllipsis(): void
    {
        $map = new FakeKeyMap([
            $this->b('a', 'alpha'),
            $this->b('b', 'beta'),
            $this->b('c', 'gamma'),
        ], []);
        $help = (new Help())->width(15);
        $out = $help->shortView($map);
        $this->assertLessThanOrEqual(15, mb_strwidth($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function testCustomEllipsisGlyph(): void
    {
        $map = new FakeKeyMap([
            $this->b('a', 'alpha'),
            $this->b('b', 'beta'),
        ], []);
        $help = (new Help())->width(8)->withEllipsis('...');
        $out  = $help->shortView($map);
        $this->assertStringEndsWith('...', $out);
    }

    public function testWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Help())->width(-1);
    }

    public function testGetWidthRoundTrips(): void
    {
        $this->assertSame(0,  (new Help())->getWidth());
        $this->assertSame(20, (new Help())->width(20)->getWidth());
    }

    public function testShortHelpViewBypassKeyMap(): void
    {
        $bs = [$this->b('q', 'quit'), $this->b('?', 'help')];
        $this->assertSame('q quit • ? help', (new Help())->shortHelpView($bs));
    }

    public function testFullHelpViewBypassKeyMap(): void
    {
        $cols = [
            [$this->b('a', 'alpha'), $this->b('b', 'beta')],
            [$this->b('c', 'gamma')],
        ];
        $out = (new Help())->fullHelpView($cols);
        $this->assertStringContainsString('a alpha', $out);
        $this->assertStringContainsString('c gamma', $out);
    }

    public function testCustomFullSeparator(): void
    {
        // Two-row column → fullSeparator joins the rows.
        $map = new FakeKeyMap([], [
            [$this->b('a', 'alpha'), $this->b('b', 'beta')],
        ]);
        $help = (new Help())->withFullSeparator(' :: ');
        $this->assertSame('a alpha :: b beta', $help->fullView($map));
    }

    public function testWithStylesAppliesShortKeyStyle(): void
    {
        $map = new FakeKeyMap([$this->b('a', 'alpha')], []);
        $styles = new Styles(shortKey: Style::new()->bold());
        $help = (new Help())->withStyles($styles);
        $rendered = $help->shortView($map);
        // Bold opens with \x1b[1m and resets with \x1b[0m around 'a'.
        $this->assertStringContainsString("\x1b[1ma\x1b[0m", $rendered);
        $this->assertStringContainsString('alpha', $rendered);
    }

    public function testWithStylesAppliesShortDescStyle(): void
    {
        $map = new FakeKeyMap([$this->b('a', 'alpha')], []);
        $styles = new Styles(shortDesc: Style::new()->italic());
        $help = (new Help())->withStyles($styles);
        $rendered = $help->shortView($map);
        $this->assertStringContainsString("\x1b[3malpha\x1b[0m", $rendered);
    }

    public function testWithStylesAppliesShortSeparator(): void
    {
        $map = new FakeKeyMap([$this->b('a', 'alpha'), $this->b('b', 'beta')], []);
        $styles = new Styles(shortSeparator: Style::new()->faint());
        $help = (new Help())->withStyles($styles);
        $rendered = $help->shortView($map);
        // Default separator is ' • ' — wrapped in faint SGR.
        $this->assertStringContainsString("\x1b[2m • \x1b[0m", $rendered);
    }

    public function testWithStylesAppliesFullKey(): void
    {
        $map = new FakeKeyMap([], [[$this->b('a', 'alpha')]]);
        $styles = new Styles(fullKey: Style::new()->underline());
        $help = (new Help())->withStyles($styles);
        $rendered = $help->fullView($map);
        $this->assertStringContainsString("\x1b[4ma\x1b[0m", $rendered);
    }

    public function testWithStylesNullClearsStyles(): void
    {
        $map = new FakeKeyMap([$this->b('a', 'alpha')], []);
        $help = (new Help())
            ->withStyles(new Styles(shortKey: Style::new()->bold()))
            ->withStyles(null);
        $this->assertSame('a alpha', $help->shortView($map));
        $this->assertNull($help->getStyles());
    }

    public function testStylesUniformHelper(): void
    {
        $bold = Style::new()->bold();
        $s = Styles::uniform($bold);
        $this->assertSame($bold, $s->shortKey);
        $this->assertSame($bold, $s->fullDesc);
        $this->assertSame($bold, $s->ellipsis);
    }

    public function testUpdateWithBindingTogglesShowAll(): void
    {
        $h = new Help();
        $this->assertFalse($h->showAll);
        $toggle = (new Binding(['?']))->withHelp('?', 'help');
        $h2 = $h->updateWithBinding(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, '?'), $toggle);
        $this->assertTrue($h2->showAll);
        $h3 = $h2->updateWithBinding(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, '?'), $toggle);
        $this->assertFalse($h3->showAll);
    }

    public function testUpdateWithBindingIgnoresUnrelatedKey(): void
    {
        $h = new Help();
        $toggle = (new Binding(['?']))->withHelp('?', 'help');
        $h2 = $h->updateWithBinding(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, 'x'), $toggle);
        $this->assertFalse($h2->showAll);
    }

    public function testUpdateWithBindingIgnoresDisabled(): void
    {
        $h = new Help();
        $toggle = (Binding::withDisabled(['?']))->withHelp('?', 'help');
        $h2 = $h->updateWithBinding(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, '?'), $toggle);
        $this->assertFalse($h2->showAll);
    }
}
