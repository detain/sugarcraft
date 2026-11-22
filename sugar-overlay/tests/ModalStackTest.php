<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Overlay\Modal;
use SugarCraft\Overlay\ModalStack;
use SugarCraft\Overlay\Placement;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

/**
 * ModalStack: push/top/pop, key routing to the top modal (Escape closes,
 * other keys update it), and bottom-to-top rendering through each layer's
 * veil and placement.
 */
final class ModalStackTest extends TestCase
{
    private const BG = "..........\n..........\n..........";

    /** A modal that shows its label plus the keys it has seen; Escape closes it. */
    private static function modal(string $label, string $typed = ''): Modal
    {
        return new class ($label, $typed) implements Modal {
            public function __construct(public readonly string $label, public readonly string $typed)
            {
            }

            public function render(int $cols, int $rows): string
            {
                return $this->label . $this->typed;
            }

            public function handleKey(KeyMsg $msg): array
            {
                if ($msg->type === KeyType::Escape) {
                    return [null, null];
                }

                return [new self($this->label, $this->typed . $msg->rune), static fn () => null];
            }
        };
    }

    private static function key(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    public function testNewIsEmpty(): void
    {
        $stack = ModalStack::new();

        $this->assertTrue($stack->isEmpty());
        $this->assertCount(0, $stack);
        $this->assertNull($stack->top());
        $this->assertSame([], $stack->all());
    }

    public function testPushPutsTheModalOnTop(): void
    {
        $a = self::modal('A');
        $b = self::modal('B');
        $stack = ModalStack::new()->push($a)->push($b);

        $this->assertCount(2, $stack);
        $this->assertSame($b, $stack->top());
        $this->assertSame([$a, $b], $stack->all());
    }

    public function testPushAndPopAreImmutable(): void
    {
        $empty = ModalStack::new();
        $one = $empty->push(self::modal('A'));
        $popped = $one->pop();

        $this->assertTrue($empty->isEmpty());
        $this->assertCount(1, $one);
        $this->assertTrue($popped->isEmpty());
        $this->assertSame($empty, $empty->pop(), 'popping an empty stack is a no-op');
    }

    public function testKeysGoToTheTopModalOnly(): void
    {
        $a = self::modal('A');
        $stack = ModalStack::new()->push($a)->push(self::modal('B'));

        [$stack, $cmd] = $stack->handleKey(self::key('x'));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertSame('Bx', $stack->top()?->render(10, 3));
        $this->assertSame($a, $stack->all()[0], 'the modal beneath never sees the key');
    }

    public function testAModalReturningNullClosesItself(): void
    {
        $stack = ModalStack::new()->push(self::modal('A'))->push(self::modal('B'));

        [$stack, $cmd] = $stack->handleKey(new KeyMsg(KeyType::Escape));

        $this->assertNull($cmd);
        $this->assertCount(1, $stack);
        $this->assertSame('A', $stack->top()?->render(10, 3));
    }

    public function testAnEmptyStackIgnoresKeys(): void
    {
        $stack = ModalStack::new();

        [$same, $cmd] = $stack->handleKey(self::key('x'));

        $this->assertSame($stack, $same);
        $this->assertNull($cmd);
    }

    public function testRenderPaintsLayersBottomToTopAtTheirPlacements(): void
    {
        $stack = ModalStack::new()
            ->push(self::modal('AAAA'), Veil::new()->withPlacement(Placement::new(Position::TOP, Position::LEFT)))
            ->push(self::modal('BB'), Veil::new()->withPlacement(Placement::new(Position::TOP, Position::LEFT, 2, 0)));

        $this->assertSame("AABB......\n..........\n..........", $stack->render(self::BG, 10, 3));
    }

    public function testTheDefaultLayerIsTheCenteredModalPreset(): void
    {
        $stack = ModalStack::new()->push(self::modal('[ok]'));

        $this->assertSame(Veil::centered('[ok]', self::BG), $stack->render(self::BG, 10, 3));
    }

    public function testRenderOfAnEmptyStackReturnsTheBackground(): void
    {
        $this->assertSame(self::BG, ModalStack::new()->render(self::BG, 10, 3));
    }

    public function testRenderHandsTheCanvasSizeToEachModal(): void
    {
        $sized = new class implements Modal {
            public function render(int $cols, int $rows): string
            {
                return "{$cols}x{$rows}";
            }

            public function handleKey(KeyMsg $msg): array
            {
                return [$this, null];
            }
        };
        $out = ModalStack::new()->push($sized, Veil::new()->withPlacement(Placement::new(Position::TOP, Position::LEFT)))->render(self::BG, 80, 24);

        $this->assertStringStartsWith('80x24', $out);
    }

    public function testThePushCeilingMatchesVeilStack(): void
    {
        $stack = ModalStack::new();
        $modal = self::modal('m');
        for ($i = 0; $i < ModalStack::MAX_LAYERS; $i++) {
            $stack = $stack->push($modal);
        }

        $this->expectException(\OverflowException::class);
        $stack->push($modal);
    }
}
