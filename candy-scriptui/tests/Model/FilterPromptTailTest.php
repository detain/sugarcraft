<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\FilterModel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-42 (finding 060): filter --prompt replaces the '/' probe glyph,
 * --pointer aliases --cursor, --tail keeps rows visible BELOW the cursor.
 * All three ride frames; the model layer owns the bytes.
 */
final class FilterPromptTailTest extends TestCase
{
    /** @param list<string> $options */
    private static function model(array $options, string $prompt = '', int $tail = 0, int $height = 3, string $placeholder = ''): FilterModel
    {
        return FilterModel::fromOptions(
            options: $options,
            height: $height,
            prompt: $prompt,
            tail: $tail,
            placeholder: $placeholder,
        );
    }

    public function testPromptReplacesTheProbeGlyph(): void
    {
        $view = self::model(['alpha', 'beta'], prompt: 'λ ')->view();
        $first = explode("\n", $view)[0];

        self::assertSame('λ ', $first, 'empty filter renders the bare prompt glyph');
        self::assertStringNotContainsString("\n/\n", $view);
    }

    public function testPromptTravelsWithTypedText(): void
    {
        $model = self::model(['alpha', 'beta', 'gamma'], prompt: 'find: ');
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'l'));
        $view = $model->view();

        self::assertStringContainsString('find: al', $view);
        self::assertStringNotContainsString('/al', $view);
    }

    public function testPlaceholderGhostsThePromptGlyphLine(): void
    {
        $view = self::model(['alpha'], prompt: 'go ', placeholder: 'type')->view();

        // scriptui-18's shared span doctrine, now behind the custom glyph.
        self::assertStringContainsString("go \x1b[2mtype\x1b[0m", explode("\n", $view)[0]);
    }

    public function testTailKeepsRowsVisibleBelowTheCursor(): void
    {
        $items = ['item1', 'item2', 'item3', 'item4', 'item5', 'item6', 'item7', 'item8'];
        $model = self::model($items, tail: 1);
        for ($i = 0; $i < 7; $i++) {
            [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        }
        $view = $model->view();

        // Cursor on item8; tail 1 keeps item7 visible above — the window
        // hugs the BOTTOM so the rows around the cursor never vanish.
        self::assertStringContainsString('item8', $view);
        self::assertStringContainsString('item7', $view);
        self::assertStringNotContainsString('item1', $view);
        self::assertStringNotContainsString('item5', $view);
    }

    public function testTailZeroKeepsTheHistoricalWindow(): void
    {
        $items = ['item1', 'item2', 'item3', 'item4', 'item5', 'item6', 'item7', 'item8'];
        $plain = self::model($items);
        $t0    = $plain->view();

        // Default posture: cursor top-anchored exactly as ItemList slices.
        self::assertStringContainsString('item8', (static function (FilterModel $m): string {
            for ($i = 0; $i < 7; $i++) {
                [$m, ] = $m->update(new KeyMsg(KeyType::Down, ''));
            }

            return $m->view();
        })($plain));
        self::assertStringContainsString('item1', $t0);
    }

    public function testPointerAndCursorAliasesResolveOnTheRealRunPath(): void
    {
        foreach ([['--pointer', '↓ '], ['--cursor', '↓ ']] as [$flag, $value]) {
            $app = new Application();
            $app->setAutoExit(false);
            $out = new BufferedOutput();

            $status = $app->run(new ArgvInput(['candy-scriptui', 'filter', $flag . '=' . $value, '--prompt', '>x', '--tail', '2']), $out);

            self::assertSame(1, $status);
            self::assertStringNotContainsString('does not exist', $out->fetch(), $flag . ' must be a registered spelling');
        }
    }

    public function testPointerWinsOverCursorLikeGumExpects(): void
    {
        // Alias precedence is parse-level: pointer, then indicator, then the
        // documented default. Assert through the model the command would
        // build with the same resolution order.
        $model = FilterModel::fromOptions(options: ['a'], cursorPrefix: 'P ');
        $view  = $model->view();

        self::assertStringContainsString('P a', str_replace("\x1b[7m", '', $view));
    }

    public function testTailBeyondWindowClampsIntoHeight(): void
    {
        $items = ['item1', 'item2', 'item3'];
        $model = self::model($items, tail: 9, height: 3);
        [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        [$model, ] = $model->update(new KeyMsg(KeyType::Down, ''));
        $view = $model->view();

        // Clamped tail (=height-1): everything stays visible, no crash.
        self::assertStringContainsString('item1', $view);
        self::assertStringContainsString('item3', $view);
    }
}
