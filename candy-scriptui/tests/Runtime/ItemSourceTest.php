<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Runtime\ItemSource;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-46 (finding 064): all four item spellings source the same list,
 * stdin stays the fallback, and the select-if-one idiom works on both
 * choose and filter.
 */
final class ItemSourceTest extends TestCase
{
    public function testPositionalsComeFirstThenItemChunks(): void
    {
        self::assertSame(
            ['p1', 'p2', 'a', 'b'],
            ItemSource::collect(['p1', 'p2'], ['a,b'], static fn (): array => ['ignored']),
        );
    }

    public function testStdinIsTheFallbackOnly(): void
    {
        self::assertSame(['s1', 's2'], ItemSource::collect([], [], static fn (): array => ['s1', 's2']));
        self::assertSame(['x'], ItemSource::collect(['x'], [], static fn (): array => ['s1', 's2']));
    }

    public function testNoStdinLegMeansEmpty(): void
    {
        self::assertSame([], ItemSource::collect([], [], null));
    }

    public function testChoosePositionalStillAutoPicks(): void
    {
        self::assertSame("Pizza\n", $this->displayOf('choose --select-if-one Pizza'));
    }

    public function testChooseItemAutoPicks(): void
    {
        self::assertSame("Pizza\n", $this->displayOf('choose --item Pizza --select-if-one'));
    }

    public function testFilterItemAutoPicks(): void
    {
        self::assertSame("Pizza\n", $this->displayOf('filter --item Pizza --select-if-one'));
    }

    public function testFilterPositionalAutoPicks(): void
    {
        self::assertSame("Pizza\n", $this->displayOf('filter Pizza --select-if-one'));
    }

    public function testCommaSplitFeedsTwoItems(): void
    {
        // Two items — auto-pick must NOT fire (proves the split happened).
        self::assertSame(1, $this->exitCode('filter --item=Pizza,Burger --select-if-one'));
    }

    public function testEscapedCommaStaysOneItem(): void
    {
        self::assertSame("a,b\n", $this->displayOf('choose --item=a\\,b --select-if-one'));
    }

    public function testChooseWithoutItemsRefuses(): void
    {
        // No stdin leg exists for choose: the honest refusal is exit 1.
        self::assertSame(1, $this->exitCode('choose'));
    }

    private function displayOf(string $line): string
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $tokens = array_merge(['candy-scriptui'], preg_split('/\s+/', $line) ?: []);
        $status = $app->run(new ArgvInput($tokens), $out);
        self::assertSame(0, $status, $line);

        return $out->fetch();
    }

    private function exitCode(string $line): int
    {
        $app = new Application();
        $app->setAutoExit(false);

        $tokens = array_merge(['candy-scriptui'], preg_split('/\s+/', $line) ?: []);

        return $app->run(new ArgvInput($tokens), new BufferedOutput());
    }
}
