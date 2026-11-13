<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\ConfirmModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-84: the display-parity micro-flags (pager --hard-wrap/--fade,
 * confirm --pointer, format --code/--foreground/--background) render
 * instead of dying as unknown options.
 */
final class MicroFlagsTest extends TestCase
{
    protected function tearDown(): void
    {
        ColourGate::resetAmbient();
    }

    public function testHardWrapBreaksMidWordAtExactWidth(): void
    {
        $wrapped = PagerModel::hardWrap("abcdefghij\nxy", 4);

        self::assertSame("abcd\nefgh\nij\nxy", $wrapped);
    }

    public function testHardWrapKeepsEveryRowWithinWidthForStyledInput(): void
    {
        $wrapped = PagerModel::hardWrap("\x1b[31m" . str_repeat('x', 25) . "\x1b[0m", 10);

        foreach (explode("\n", $wrapped) as $row) {
            self::assertLessThanOrEqual(10, Width::string($row));
        }
        self::assertSame(str_repeat('x', 25), Ansi::strip(str_replace("\n", '', $wrapped)));
    }

    public function testFadeDimsOnlyTheTrailingRowsWhileMoreContentLies(): void
    {
        ColourGate::resetAmbient();
        $content = implode("\n", array_map(static fn (int $i): string => "line$i", range(1, 20)));
        $model = PagerModel::fromContent($content, 20, 5, fade: 2);

        $rows = explode("\n", $model->view());

        self::assertStringNotContainsString("\x1b[2m", $rows[0]);
        self::assertStringNotContainsString("\x1b[2m", $rows[2]);
        self::assertStringContainsString("\x1b[2mline4", $rows[3]);
        self::assertStringContainsString("\x1b[2mline5", $rows[4]);
    }

    public function testFadeIsOffAtTheEndOfTheDocument(): void
    {
        $model = PagerModel::fromContent("a\nb\nc", 20, 5, fade: 2);

        self::assertStringNotContainsString("\x1b[2m", $model->view());
    }

    public function testFadeZeroIsTheHistoricalFrame(): void
    {
        $content = implode("\n", range(1, 20));

        self::assertSame(
            PagerModel::fromContent($content, 20, 5)->view(),
            PagerModel::fromContent($content, 20, 5, fade: 0)->view(),
        );
    }

    public function testPointerMarksTheChosenAnswerAndFollowsTheToggle(): void
    {
        $model = ConfirmModel::newPrompt('Go?', default: true, pointer: '›');
        $yesRow = Ansi::strip(explode("\n", $model->view())[1]);
        self::assertStringStartsWith('› ', ltrim($yesRow, '') );
        self::assertStringContainsString('Yes', $yesRow);

        [$toggled] = $model->update(new KeyMsg(KeyType::Right));
        $noRow = Ansi::strip(explode("\n", $toggled->view())[1]);
        self::assertStringStartsWith('  ', $noRow);
        self::assertMatchesRegularExpression('/›\s*No/u', $noRow);
        self::assertSame(Width::string($yesRow) , Width::string($noRow));
    }

    public function testNoPointerLeavesTheFrameUntouched(): void
    {
        self::assertSame(
            ConfirmModel::newPrompt('Go?', default: true)->view(),
            ConfirmModel::newPrompt('Go?', default: true, pointer: '')->view(),
        );
    }

    public function testFormatCodeFlagIsTypeCode(): void
    {
        [$status, $viaFlag] = $this->runCli('format --code --language php --theme plain "echo 1;"');
        [, $viaType]        = $this->runCli('format --type code --language php --theme plain "echo 1;"');

        self::assertSame(0, $status);
        self::assertSame($viaType, $viaFlag);
        self::assertStringContainsString('echo 1;', $viaFlag);
    }

    public function testFormatCodeConflictsWithAnotherExplicitType(): void
    {
        [$status] = $this->runCli('format --code --type emoji "x"');

        self::assertSame(1, $status);
    }

    public function testFormatForegroundBackgroundLayerUnderTheRenderedText(): void
    {
        ColourGate::install();
        [$status, $out] = $this->runCli('format --type emoji --foreground "#ff0000" --background "#0000ff" "hi"');

        self::assertSame(0, $status);
        self::assertStringContainsString('38;2;255;0;0', $out);
        self::assertStringContainsString('48;2;0;0;255', $out);
        self::assertSame('hi', trim(Ansi::strip($out)));
    }

    public function testFormatColoursDropUnderNoAnsi(): void
    {
        [$status, $out] = $this->runCli('format --type emoji --no-ansi --foreground red "hi"');

        self::assertSame(0, $status);
        self::assertSame("hi\n", $out);
    }

    public function testFormatBadColourRefuses(): void
    {
        [$status, $out] = $this->runCli('format --type emoji --foreground nonsense "hi"');

        self::assertSame(1, $status);
        self::assertSame('', trim($out));
    }

    /** @return array{0:int,1:string} */
    private function runCli(string $argv): array
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();
        $status = $app->run(new StringInput($argv), $out);

        return [$status, $out->fetch()];
    }
}
