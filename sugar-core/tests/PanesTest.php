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
use SugarCraft\Core\Util\Width;

/**
 * A simple Model that returns a fixed view string for testing.
 */
final class StringModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public function __construct(private string $content)
    {
    }

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

    public function __construct(private int $count = 0)
    {
    }

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
        $model = new class ($lines) implements Model {
            use \SugarCraft\Core\SubscriptionCapable;

            public function __construct(private array $lines)
            {
            }

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
    public function testPaneClipsRowsAndColumnsToItsBounds(): void
    {
        // sugar-core-027: bounds are a HARD partition. Five rows of six
        // cells into a 2x3 box leaves exactly two rows of at most three
        // visible cells; the overflow is discarded, never bled.
        $pane = new Pane(new StringModel("123456\nabcdef\nggg\nlong4\nlong5"), new Rect(0, 0, 3, 2));
        $view = $pane->view();

        $body = \substr($view, \strlen(Ansi::cursorTo(1, 1)));
        $lines = \explode("\n", $body);
        $this->assertCount(2, $lines, 'rows past bounds.height must be dropped');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(3, Width::string($line), 'row wider than bounds.width must be clipped');
        }
        $this->assertStringNotContainsString('ddd', $body);
        $this->assertStringNotContainsString('long4', $body);
    }

    public function testOversizedPaneNeverBleedsIntoItsSibling(): void
    {
        // The two-pane fixture: an oversized upper pane contributes only
        // its own cell budget, so no orphan rows land between the two
        // origin jumps (the old bleed: rows 3-5 scrolled under the lower
        // pane's cursorTo and overwrote its cells).
        $upper = new Pane(new StringModel("aaa\nbbb\nccc\nddd\neee"), new Rect(0, 0, 10, 2));
        $lower = new Pane(new StringModel("z"), new Rect(0, 2, 10, 2));
        $view = (new Panes([$upper, $lower]))->view();

        $upperPos = Ansi::cursorTo(1, 1);
        $lowerPos = Ansi::cursorTo(3, 1);
        $lowerStart = \strpos($view, $lowerPos);
        $this->assertIsInt($lowerStart);
        $upperBody = \substr($view, \strlen($upperPos), $lowerStart - \strlen($upperPos));
        $this->assertSame(1, \substr_count($upperBody, "\n"), 'upper pane spilled a row into the lower pane region');
        $this->assertStringNotContainsString('ddd', $view);
        $this->assertStringNotContainsString('eee', $view);
    }

    public function testActiveIndexIsClampedIntoRange(): void
    {
        $panes = [new Pane(new StringModel('A'), new Rect(0, 0, 5, 1)), new Pane(new StringModel('B'), new Rect(5, 0, 5, 1))];

        $this->assertSame(1, (new Panes($panes, 99))->activeIndex(), 'over-range focus clamps to the last pane');
        $this->assertSame(0, (new Panes($panes, -3))->activeIndex(), 'negative focus clamps to zero');
        $this->assertSame(0, (new Panes([], 7))->activeIndex(), 'no panes, no focus — zero is the only truth');

        // The clamped index is also a working one: update() must not
        // dereference a missing slot (the old failure mode).
        [$next] = (new Panes($panes, 99))->update(new class implements Msg {});
        $this->assertInstanceOf(Panes::class, $next);
    }

    public function testUpdateIdentityChainSurvivesAnUnchangedActiveModel(): void
    {
        // sugar-core-036 PERF-13: StringModel::update() returns $this.
        // If Pane::update() still cloned the identity, Panes::update()
        // would rebuild the list — and every enclosing composition tree
        // — on a keystroke that changed nothing.
        $panes = new Panes([new Pane(new StringModel('A'), new Rect(0, 0, 5, 1))]);
        $result = $panes->update(new class implements Msg {});

        $this->assertSame($panes, $result[0], 'no-op messages must not rebuild the composition');
        $this->assertNull($result[1]);
    }

    public function testChangedModelStillRebuildsTheComposition(): void
    {
        // The other side of the identity pin: churn must remain real
        // when the active model actually changes.
        $panes = new Panes([new Pane(new CountingModel(0), new Rect(0, 0, 5, 1))]);
        $result = $panes->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertNotSame($panes, $result[0]);
    }
}
