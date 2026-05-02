<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Help;

use CandyCore\Bits\Help\Help;
use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\KeyMap;
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
}
