<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Pane;
use SugarCraft\Core\Panes;
use SugarCraft\Core\Rect;
use SugarCraft\Core\Util\Ansi;

/**
 * A simple Model that returns a fixed view string for testing.
 */
final class StringModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function __construct(private string $content) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return $this->content;
    }
}

/**
 * A stateful Model that tracks key presses for testing update routing.
 */
final class CountingModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function __construct(private int $count = 0) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'x') {
            $newCount = $this->count + 1;
            return [new self($newCount), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "count: {$this->count}";
    }

    public function count(): int
    {
        return $this->count;
    }
}

final class PanesTest extends TestCase
{
    public function testPanesRendersAllContent(): void
    {
        $left = new StringModel("left content");
        $right = new StringModel("right content");

        $panes = new Panes([
            new Pane($left, new Rect(0, 0, 40, 24)),
            new Pane($right, new Rect(40, 0, 40, 24)),
        ]);

        $view = $panes->view();
        $this->assertStringContainsString('left content', $view);
        $this->assertStringContainsString('right content', $view);
    }

    public function testPanesContentPositionedAtPaneOrigin(): void
    {
        $model = new StringModel("hello");
        $pane = new Pane($model, new Rect(5, 3, 20, 10));

        $view = $pane->view();

        // Content should be preceded by cursor positioning.
        $this->assertStringContainsString(Ansi::cursorTo(4, 6), $view);
        $this->assertStringContainsString('hello', $view);
    }

    public function testPanesRoutesToActivePane(): void
    {
        $countModel = new CountingModel();
        $staticModel = new StringModel("static");

        $panes = new Panes([
            new Pane($countModel, new Rect(0, 0, 40, 24)),
            new Pane($staticModel, new Rect(40, 0, 40, 24)),
        ]);

        // Send 'x' key to the active pane (index 0).
        $msg = new KeyMsg(KeyType::Char, 'x');
        [$updated, $cmd] = $panes->update($msg);

        // The counting model inside pane 0 should have incremented.
        $this->assertSame(1, $updated->panes()[0]->model->count());
    }

    public function testPanesTabSwitchesActive(): void
    {
        $paneA = new Pane(new StringModel("A"), new Rect(0, 0, 40, 24));
        $paneB = new Pane(new StringModel("B"), new Rect(40, 0, 40, 24));

        $panes = new Panes([$paneA, $paneB], activeIndex: 0);
        $this->assertSame(0, $panes->activeIndex());

        // Tab should advance to next pane.
        $tabMsg = new KeyMsg(KeyType::Tab);
        [$next, $cmd] = $panes->update($tabMsg);

        $this->assertSame(1, $next->activeIndex());

        // Another Tab should wrap around.
        [$wrapped, $cmd] = $next->update($tabMsg);
        $this->assertSame(0, $wrapped->activeIndex());
    }

    public function testPanesInitialActivePane(): void
    {
        $paneA = new Pane(new StringModel("A"), new Rect(0, 0, 40, 24));
        $paneB = new Pane(new StringModel("B"), new Rect(40, 0, 40, 24));

        $panes = new Panes([$paneA, $paneB], activeIndex: 1);
        $this->assertSame(1, $panes->activeIndex());
    }

    public function testPanesUpdateReturnsNewInstance(): void
    {
        $pane = new Pane(new StringModel("content"), new Rect(0, 0, 40, 24));
        $panes = new Panes([$pane]);

        $msg = new KeyMsg(KeyType::Tab);
        [$updated, $cmd] = $panes->update($msg);

        $this->assertNotSame($panes, $updated);
    }

    public function testPanesEmptyReturnsNoOutput(): void
    {
        $panes = new Panes([]);
        $this->assertSame('', $panes->view());
        $this->assertSame(0, $panes->count());
    }

    public function testPanesCountReturnsCorrectValue(): void
    {
        $paneA = new Pane(new StringModel("A"), new Rect(0, 0, 40, 24));
        $paneB = new Pane(new StringModel("B"), new Rect(40, 0, 40, 24));
        $paneC = new Pane(new StringModel("C"), new Rect(80, 0, 40, 24));

        $panes = new Panes([$paneA, $paneB, $paneC]);
        $this->assertSame(3, $panes->count());
    }

    public function testPaneWithViewportModel(): void
    {
        // Create a simple viewport-like model and wrap it in a Pane.
        $lines = ["line one", "line two", "line three"];
        $model = new class($lines) implements Model
        {
            use \SugarCraft\Core\SubscriptionCapable;

            public function __construct(private array $lines) {}

            public function init(): ?\Closure { return null; }

            public function update(Msg $msg): array { return [$this, null]; }

            public function view(): string
            {
                return implode("\n", $this->lines);
            }
        };

        $pane = new Pane($model, new Rect(10, 5, 30, 3));
        $view = $pane->view();

        $this->assertStringContainsString(Ansi::cursorTo(6, 11), $view);
        $this->assertStringContainsString("line one", $view);
        $this->assertStringContainsString("line two", $view);
        $this->assertStringContainsString("line three", $view);
    }

    public function testPaneUpdateDelegatesToModel(): void
    {
        $countModel = new CountingModel();
        $pane = new Pane($countModel, new Rect(0, 0, 40, 24));

        $msg = new KeyMsg(KeyType::Char, 'x');
        [$updatedPane, $cmd] = $pane->update($msg);

        $this->assertNotSame($pane, $updatedPane);
        $this->assertSame(1, $updatedPane->model->count());
    }

    public function testPanesViewRendersAllPanesWithPositioning(): void
    {
        $paneA = new Pane(new StringModel("top"), new Rect(0, 0, 20, 10));
        $paneB = new Pane(new StringModel("bottom"), new Rect(0, 10, 20, 10));

        $panes = new Panes([$paneA, $paneB]);

        $view = $panes->view();

        // Both panes should be rendered with their respective cursor positions.
        $this->assertStringContainsString(Ansi::cursorTo(1, 1), $view);
        $this->assertStringContainsString(Ansi::cursorTo(11, 1), $view);
        $this->assertStringContainsString('top', $view);
        $this->assertStringContainsString('bottom', $view);
    }
}
