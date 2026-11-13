<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\Style\Style;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * scriptui-37 — the README's "--no-ansi is FINAL, every SGR escape is
 * dropped, decoration included" claim must hold for the INTERACTIVE
 * frames too, not just Style-built elements. The cursor row, the fuzzy
 * emphasis, and the pager highlight were forged with raw Ansi::sgr()
 * calls that ignored the resolved tier; they now go through Emphasis,
 * which strips to plain bytes under a NoTty capability.
 */
final class NoAnsiFrameTest extends TestCase
{
    protected function tearDown(): void
    {
        ColourGate::resetAmbient();
    }

    public function testNoAnsiFlagResolvesToNoTtyThroughTheGate(): void
    {
        self::assertSame(
            ColorProfile::NoTty,
            ColourGate::cap(new ArgvInput(['candy-scriptui', 'choose', '--no-ansi'])),
        );
    }

    public function testChooseCursorRowIsEscapeFreeUnderNoAnsi(): void
    {
        ColourGate::install(new ArgvInput(['candy-scriptui', 'choose', '--no-ansi']));

        $model = ChooseModel::fromOptions(['alpha', 'beta', 'gamma'], cursorPrefix: '> ');
        [$model] = $model->update(new KeyMsg(KeyType::Down, ''));
        $frame = $model->view();

        self::assertStringNotContainsString("\x1b", $frame);
        self::assertStringContainsString('> beta', $frame);
    }

    public function testFilterFuzzyEmphasisIsEscapeFreeUnderNoAnsi(): void
    {
        ColourGate::install(new ArgvInput(['candy-scriptui', 'filter', '--no-ansi']));

        $model = FilterModel::fromOptions(['banana', 'bandana', 'cherry']);
        foreach (['b', 'a', 'n'] as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        self::assertStringNotContainsString("\x1b", $model->view());
        self::assertStringContainsString('banana', $model->view());
    }

    public function testPagerHighlightIsEscapeFreeUnderNoAnsi(): void
    {
        ColourGate::install(new ArgvInput(['candy-scriptui', 'pager', '--no-ansi']));

        $model = PagerModel::fromContent("alpha line\nzulu line\n", match: 'alpha');

        self::assertStringNotContainsString("\x1b", $model->view());
        self::assertStringContainsString('alpha', $model->view());
    }

    public function testDecorationSurvivesWhenTheAmbientIsUnrestricted(): void
    {
        // Negative control: without the gate, the same frames DO carry
        // reverse video — proving the strip above is the gate's doing.
        Style::setDefaultProfile(null);

        $model = ChooseModel::fromOptions(['alpha', 'beta'], cursorPrefix: '> ');
        [$model] = $model->update(new KeyMsg(KeyType::Down, ''));

        self::assertStringContainsString("\x1b[7m", $model->view());
    }

    public function testAsciiTierKeepsDecoration(): void
    {
        // NO_COLOR-style tier: colour drops, decoration stays — Emphasis
        // must not confuse Ascii with NoTty.
        ColourGate::install(new ArgvInput(['candy-scriptui', 'choose']));
        Style::setDefaultProfile(ColorProfile::Ascii);

        $model = ChooseModel::fromOptions(['alpha', 'beta'], cursorPrefix: '> ');
        [$model] = $model->update(new KeyMsg(KeyType::Down, ''));

        self::assertStringContainsString("\x1b[7m", $model->view());
    }
}
