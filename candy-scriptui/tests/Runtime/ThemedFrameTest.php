<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\ScriptUi\Runtime\Geometry;
use SugarCraft\ScriptUi\Runtime\StyledFrame;
use SugarCraft\ScriptUi\Runtime\StyleOptions;
use SugarCraft\ScriptUi\Style\StyleBuilder;
use SugarCraft\Core\Util\ColorProfile;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-26 (candy-scriptui-025): the lipgloss surface gum embeds in every
 * interactive command must register, build, and actually paint — border,
 * colour and the gate precedence all included.
 */
final class ThemedFrameTest extends TestCase
{
    public function testStyleOptionsRegisterOnEveryInteractiveCommand(): void
    {
        $app = new Application();
        foreach (['input', 'choose', 'filter', 'confirm', 'write', 'file', 'pager'] as $name) {
            $command = $app->get($name);
            foreach (['border', 'padding', 'margin', 'foreground', 'background', 'bold', 'align'] as $flag) {
                self::assertTrue(
                    $command->getDefinition()->hasOption($flag),
                    "$name must accept --$flag (gum embeds the style surface everywhere)",
                );
            }
        }
    }

    public function testFramePaintsBorderAroundTheModelBody(): void
    {
        $inner = ChooseModel::fromOptions(['alpha', 'beta']);
        $frame = new StyledFrame($inner, StyleBuilder::fromFlags(['border' => 'normal']));
        $view  = $frame->view();

        self::assertStringStartsWith('┌', $view);
        self::assertMatchesRegularExpression('/│ ?>?\s*(\x1b\[7m)?alpha/', $view, 'body rows ride inside the border, cursor styling intact');
        self::assertStringContainsString('└', $view);
    }

    public function testFrameSurvivesUpdatesAndPreservesIdentity(): void
    {
        $inner = ChooseModel::fromOptions(['alpha', 'beta']);
        $frame = new StyledFrame($inner, StyleBuilder::fromFlags(['border' => 'rounded']));

        // An unclaimed key must keep the chrome (re-wrapped or not, the
        // border rides the session), and must not move the cursor.
        [$kept, ] = $frame->update(new KeyMsg(KeyType::F9, ''));
        self::assertInstanceOf(StyledFrame::class, $kept);
        self::assertStringStartsWith('╭', $kept->view());

        // A moved cursor re-wraps and the chrome rides along.
        [$moved, ] = $frame->update(new KeyMsg(KeyType::Down, ''));
        self::assertNotSame($frame, $moved);
        self::assertInstanceOf(StyledFrame::class, $moved);
        self::assertStringStartsWith('╭', $moved->view());
        self::assertStringContainsString('╯', $moved->view());
    }

    public function testBadThemeRefusesBeforeAnythingElse(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new ArgvInput(['candy-scriptui', 'choose', '--border', 'zigzag', 'a', 'b']), $out);

        self::assertSame(1, $status);
        $display = $out->fetch();
        self::assertStringNotContainsString('does not exist', $display);
    }

    public function testNoAnsiFrameKeepsStructureDropsEscapes(): void
    {
        // The gate's input leg, not the env one: --no-ansi resolves NoTty
        // (NO_COLOR alone is the Ascii tier — decoration survives there by
        // design, see the -37 Emphasis doctrine).
        $input = new ArgvInput(['candy-scriptui', 'choose', '--no-ansi']);
        self::assertSame(ColorProfile::NoTty, ColourGate::cap($input));
        ColourGate::install($input);
        try {
            $style = StyleBuilder::fromFlags(['border' => 'normal', 'foreground' => '#ff0000', 'bold' => true]);
            $inner = ChooseModel::fromOptions(['alpha']);
            $view  = (new StyledFrame($inner, $style))->view();

            self::assertStringContainsString('┌', $view, 'border glyphs are structure, not colour');
            self::assertStringNotContainsString("\x1b[", $view, 'no SGR may survive the NoTty capability');
        } finally {
            ColourGate::resetAmbient();
        }
    }

    public function testFrameRowsAccountsForEveryVerticalChromeLeg(): void
    {
        self::assertSame(0, StyleBuilder::frameRows([]));
        self::assertSame(0, StyleBuilder::frameRows(['border' => 'none']));
        self::assertSame(2, StyleBuilder::frameRows(['border' => 'normal']));
        self::assertSame(2, StyleBuilder::frameRows(['border' => 'hidden']), 'hidden borders still occupy rows');
        self::assertSame(2, StyleBuilder::frameRows(['padding' => '1']));
        self::assertSame(3, StyleBuilder::frameRows(['margin' => '1,0,2,0']));
        self::assertSame(4, StyleBuilder::frameRows(['border' => 'normal', 'padding' => '1']));
    }

    public function testPagerAutoHeightYieldsToTheFrame(): void
    {
        Geometry::override(['cols' => 40, 'rows' => 10]);
        try {
            $plain = PagerModel::fromContent(str_repeat("line\n", 50), 0, 0);
            $themed = PagerModel::fromContent(str_repeat("line\n", 50), 0, 0, frameRows: 2);

            self::assertSame(8, $plain->viewport->height, '10 rows minus the two-line footer');
            self::assertSame(6, $themed->viewport->height, 'frame rows come off the SAME budget');
            self::assertSame(2, $themed->frameRows, 'the reservation survives resizes');
        } finally {
            Geometry::override(null);
        }
    }

    public function testEveryInteractiveCommandActuallyWrapsItsModel(): void
    {
        // scriptui-53 KEEP (wiring-pin): see the in-method rationale — the
        // frame wraps inside the live Program loop, unreachable headless, so
        // the wrap/unwrap call sites ARE the contract.
        // The wrap happens inside the live Program loop, which no headless
        // harness can screenshot — so the wiring itself is the contract:
        // each command must route its model through StyledFrame when a
        // theme is active (scriptui-26 is exactly the "plumbing exists,
        // nobody plugs it in" defect class; this pin cannot rot quietly).
        foreach (['Input', 'Choose', 'Filter', 'Confirm', 'Write', 'File', 'Pager'] as $m) {
            $src = (string) file_get_contents(__DIR__ . '/../../src/Command/' . $m . 'Command.php');
            self::assertStringContainsString(
                'new StyledFrame($model, $frame)',
                $src,
                $m . 'Command must wrap its live model in the frame',
            );
            self::assertStringContainsString('instanceof StyledFrame ? $final->inner : $final', $src, $m . 'Command must unwrap before reading results');
        }
    }

    public function testActiveAndFlagsAgreeOnEveryInputShape(): void
    {
        self::assertFalse(StyleOptions::active([]));
        self::assertFalse(StyleOptions::active(['border' => null, 'bold' => false, 'margin' => '']));
        self::assertTrue(StyleOptions::active(['border' => 'normal']));
        self::assertTrue(StyleOptions::active(['bold' => true]));
    }
}
