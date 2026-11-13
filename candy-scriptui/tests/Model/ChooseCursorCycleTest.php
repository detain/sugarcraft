<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\ChooseModel;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-44 (finding 062): gum's repeatable per-item --cursor list and
 * --selected-prefix tick glyph must render per row, not collapse into one
 * global highlight glyph.
 */
final class ChooseCursorCycleTest extends TestCase
{
    /** @param list<string> $cycle */
    private function model(array $cycle = [], string $tick = '', bool $multi = false): ChooseModel
    {
        return ChooseModel::fromOptions(
            ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'],
            limit: $multi ? 0 : 1,
            noLimit: $multi,
            cursorCycle: $cycle,
            selectedPrefix: $tick,
        );
    }

    public function testSuitsCyclePerItemUnderTheCursor(): void
    {
        $model = $this->model(['♠', '♥', '♣', '♦']);
        $head = $model->view();

        self::assertStringContainsString("♠\x1b[7mAlpha\x1b[0m", $head);
        self::assertStringNotContainsString('♥', $head);

        [$down] = $model->update(new KeyMsg(KeyType::Down, ''));
        self::assertStringContainsString("♥\x1b[7mBeta\x1b[0m", $down->view());

        [$d2] = $down->update(new KeyMsg(KeyType::Down, ''));
        [$d3] = $d2->update(new KeyMsg(KeyType::Down, ''));
        [$d4] = $d3->update(new KeyMsg(KeyType::Down, ''));
        self::assertStringContainsString("♠\x1b[7mEpsilon\x1b[0m", $d4->view(), 'item 4 cycles back to the first glyph: 4 % 4 = 0');
    }

    public function testCyclePositionsFollowTheItemNotTheFrame(): void
    {
        $model = $this->model(['a-', 'b-']);
        $view = $model->view();

        self::assertStringContainsString("a-\x1b[7mAlpha", $view);
        [$d1] = $model->update(new KeyMsg(KeyType::Down, ''));
        self::assertStringContainsString("b-\x1b[7mBeta", $d1->view());
        [$d3] = $d1->update(new KeyMsg(KeyType::Down, ''));
        [$d3] = $d3->update(new KeyMsg(KeyType::Down, ''));
        self::assertStringContainsString("b-\x1b[7mDelta", $d3->view(), 'item 3 % 2 = 1 → second glyph');
    }

    public function testSingleGlyphCycleIsTheHistoricalShape(): void
    {
        $model = $this->model(['>> ']);

        self::assertStringContainsString(">> \x1b[7mAlpha\x1b[0m", $model->view());
        self::assertStringContainsString('  Beta', $model->view(), 'non-cursor rows keep the unselected prefix');
    }

    public function testSelectedPrefixReplacesTheTickGlyph(): void
    {
        $model = $this->model(multi: true, tick: '●');
        [$model] = $model->update(new KeyMsg(KeyType::Space, ''));

        $view = $model->view();
        self::assertStringContainsString('● ' . "\x1b[7mAlpha", $view);
        self::assertStringNotContainsString("\u{2713} ", $view);
    }

    public function testDefaultTickStaysTheCheckMark(): void
    {
        $model = $this->model(multi: true);
        [$model] = $model->update(new KeyMsg(KeyType::Space, ''));

        self::assertStringContainsString("\u{2713} " . "\x1b[7mAlpha", $model->view());
    }

    public function testCursorGlyphIsPaintSanitised(): void
    {
        // A forged escape in the glyph must not survive into the frame —
        // cursor cycles are paint strings (scriptui-18 doctrine), never a
        // smuggled SGR channel.
        $model = $this->model(["\x1b[31mX", 'Y']);

        self::assertStringNotContainsString("\x1b[31m", $model->view());
    }

    public function testRepeatableAndCommaFlagsParseOnTheRealRunPath(): void
    {
        foreach (
            [
                ['candy-scriptui', 'choose', '--cursor=♠', '--cursor=♥', 'a', 'b'],
                ['candy-scriptui', 'choose', '--cursor=♠,♥', 'a', 'b'],
                ['candy-scriptui', 'choose', '--selected-prefix=●', 'a', 'b'],
            ] as $argv
        ) {
            $app = new Application();
            $app->setAutoExit(false);
            $out = new BufferedOutput();

            $status = $app->run(new \Symfony\Component\Console\Input\ArgvInput($argv), $out);

            self::assertSame(1, $status, implode(' ', $argv) . ' must reach the honest headless refusal');
            self::assertStringNotContainsString('does not exist', $out->fetch());
        }
    }

    public function testCursorPrefixAliasStillWinsASingleGlyph(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new \Symfony\Component\Console\Input\ArgvInput(['candy-scriptui', 'choose', '--cursor=♠,♥', '--cursor-prefix=Z', 'a', 'b']), $out);

        // The alias is a legal, non-conflicting spelling (single glyph wins);
        // the run proceeds to the headless refusal, never a parse error.
        self::assertSame(1, $status);
        self::assertStringNotContainsString('does not exist', $out->fetch());
    }
}
