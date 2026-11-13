<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use React\EventLoop\Loop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Model\ChooseModel;
use SugarCraft\ScriptUi\Model\FilterModel;
use SugarCraft\ScriptUi\Model\PagerModel;
use SugarCraft\ScriptUi\Runtime\Geometry;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;

/**
 * Geometry resolution, dumb-terminal posture, --no-alt-screen wiring and the
 * in-app hint footers (clusters 13/14/15).
 */
final class GeometryAndTerminalStateTest extends TestCase
{
    private const ENV_KEYS = ['COLUMNS', 'LINES', 'TERM'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        Geometry::override(null);
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        Geometry::override(null);
        foreach ($this->envSnapshot as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public function testOverrideRoundTripsAndClears(): void
    {
        Geometry::override(['cols' => 132, 'rows' => 43]);
        self::assertSame(132, Geometry::columnsOrNull());
        self::assertSame(43, Geometry::rowsOrNull());
        self::assertSame(['cols' => 132, 'rows' => 43], Geometry::size());

        Geometry::override(null);
        putenv('COLUMNS=99');
        self::assertSame(99, Geometry::columnsOrNull(), 'cleared override falls through to env');
    }

    public function testEnvironmentIsConsultedBeforeAnyProbe(): void
    {
        putenv('COLUMNS=111');
        putenv('LINES=33');
        self::assertSame(['cols' => 111, 'rows' => 33], Geometry::size());
    }

    public function testGarbageEnvironmentValuesAreIgnored(): void
    {
        putenv('COLUMNS=wide');
        putenv('LINES=0');
        // Nothing usable from env; the probe may or may not answer depending
        // on how this runner is wired, but any answer must be positive ints.
        $size = Geometry::size();
        self::assertIsArray($size);
        self::assertEmpty(array_diff($size, array_filter($size, static fn($v) => is_int($v) && $v > 0)), 'non-positive values must be filtered');
        foreach (['cols', 'rows'] as $axis) {
            if (isset($size[$axis])) {
                self::assertIsInt($size[$axis]);
                self::assertGreaterThan(0, $size[$axis]);
            }
        }
    }

    public function testListsTakeTerminalWidthAtLaunch(): void
    {
        Geometry::override(['cols' => 100, 'rows' => 30]);
        self::assertSame(100, ChooseModel::fromOptions(['a', 'b'])->list->width);
        self::assertSame(100, FilterModel::fromOptions(options: ['a'])->list->width);
    }

    public function testExplicitWidthIsHonouredButCappedAtTheTerminal(): void
    {
        Geometry::override(['cols' => 100, 'rows' => 30]);
        self::assertSame(40, ChooseModel::fromOptions(['a'], width: 40)->list->width);
        self::assertSame(100, ChooseModel::fromOptions(['a'], width: 400)->list->width);
        self::assertSame(40, FilterModel::fromOptions(options: ['a'], width: 40)->list->width);
    }

    public function testWidthIsKeptWhenTheTerminalIsUnknown(): void
    {
        // No override, no env, (on the piped CI runner) no probe: an explicit
        // width must survive untouched, auto must keep the legacy 60 cells.
        Geometry::override([]);
        self::assertSame(40, ChooseModel::fromOptions(['a'], width: 40)->list->width);
        self::assertSame(60, ChooseModel::fromOptions(['a'])->list->width);
        Geometry::override(null);
    }

    public function testPagerResolvesTerminalSizeAndReservesTheFooterRows(): void
    {
        Geometry::override(['cols' => 120, 'rows' => 40]);
        $pager = PagerModel::fromContent('body');
        self::assertSame(120, $pager->viewport->width);
        self::assertSame(38, $pager->viewport->height, 'auto height reserves the two-row footer');

        Geometry::override(['cols' => 120, 'rows' => 40]);
        $fixed = PagerModel::fromContent('body', width: 80, height: 20);
        self::assertSame([80, 20], [$fixed->viewport->width, $fixed->viewport->height], 'explicit flags win');
    }

    public function testLegacyFallbackSurvivesWhenNothingIsKnowable(): void
    {
        Geometry::override([]);
        $pager = PagerModel::fromContent('body');
        self::assertSame([80, 20], [$pager->viewport->width, $pager->viewport->height]);
    }

    public function testWindowSizeMsgRefitsThePagerLive(): void
    {
        $pager = PagerModel::fromContent('body');
        [$next, $cmd] = $pager->update(new WindowSizeMsg(150, 50));
        self::assertNull($cmd);
        self::assertInstanceOf(PagerModel::class, $next);
        self::assertSame(150, $next->viewport->width);
        self::assertSame(48, $next->viewport->height, 'scriptui-20: the resize re-fit keeps the two-row footer reservation');
        self::assertFalse($next->isExited());

        // Already-sized model keeps identity (no repaint churn per tick).
        [$same, $sameCmd] = $next->update(new WindowSizeMsg(150, 50));
        self::assertSame($next, $same);
        self::assertNull($sameCmd);
    }

    /**
     * scriptui-49: the tty/dumb/piped posture matrix now asserts HEADLESS via
     * the injected terminal probes (-34 seam) — it used to ask the real
     * environment and markTestSkip whenever a runner had no controlling
     * terminal, which is every CI runner: the safety promise shipped with
     * zero automated verification on the lane that mattered.
     */
    public function testDumbTerminalsNeverGetAltScreenOrHiddenCursors(): void
    {
        putenv('TERM=dumb');
        // A real tty hosting a dumb capability: inline, alt-screen off,
        // cursor stays visible.
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => true,
            'stderr' => true,
            'stdin'  => true,
            'tty'    => null,
        ]);
        try {
            $options = InteractiveRuntime::programOptions(loop: Loop::get(), useAltScreen: true, hideCursor: true);
            self::assertNotNull($options, 'a tty-backed dumb terminal must still host a frame');
            self::assertFalse($options->useAltScreen);
            self::assertFalse($options->hideCursor);
            self::assertTrue($options->inlineMode);
            self::assertFalse($options->unicodeMode);

            // Fully piped (no tty anywhere, no /dev/tty pair): the runtime
            // REFUSES the interactive frame instead of skipping the check.
            InteractiveRuntime::setTerminalProbesForTesting([
                'stdout' => false,
                'stderr' => false,
                'stdin'  => false,
                'tty'    => null,
            ]);
            self::assertNull(InteractiveRuntime::programOptions(loop: Loop::get(), useAltScreen: true, hideCursor: true));
        } finally {
            InteractiveRuntime::setTerminalProbesForTesting(null);
        }

        // Opt-in real-controlling-terminal variant: runs only where a ctty
        // exists, so it never masquerades as coverage on CI.
        $real = InteractiveRuntime::programOptions(loop: Loop::get(), useAltScreen: true, hideCursor: true);
        if ($real !== null) {
            self::assertFalse($real->useAltScreen);
            self::assertFalse($real->hideCursor);
            self::assertTrue($real->inlineMode);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fullscreenCommandProvider(): array
    {
        return [
            'choose' => ['ChooseCommand'],
            'filter' => ['FilterCommand'],
            'pager' => ['PagerCommand'],
            'write' => ['WriteCommand'],
            'file'   => ['FileCommand'],
        ];
    }

    #[DataProvider('fullscreenCommandProvider')]
    public function testFullscreenCommandsOfferNoAltScreen(string $class): void
    {
        // scriptui-53: the offer is proven through the live Command
        // definition (parse-time behaviour), not the addOption text. The
        // negated thread-through stays grepped: which value useAltScreen
        // receives is only observable inside a live Program render — the
        // headless shape refuses before the flag is read.
        $name = strtolower(preg_replace('/Command$/', '', $class) ?? $class);
        $command = (new \SugarCraft\ScriptUi\Application())->find($name);
        self::assertTrue(
            $command->getDefinition()->hasOption('no-alt-screen'),
            $class . ' must offer --no-alt-screen on its live definition',
        );

        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Command/' . $class . '.php');
        self::assertStringContainsString('useAltScreen: ! (bool) $input->getOption(\'no-alt-screen\')', $src);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function widthAwareCommandProvider(): array
    {
        return [
            'choose' => ['ChooseCommand'],
            'filter' => ['FilterCommand'],
            'pager'  => ['PagerCommand'],
        ];
    }

    #[DataProvider('widthAwareCommandProvider')]
    public function testWidthAwareCommandsThreadTheirFlag(string $class): void
    {
        // scriptui-53 KEEP (wiring-pin): the threaded width reaches the model
        // viewport only inside a live Program render (these commands refuse
        // headless); the model-side geometry it feeds is behaviour-tested in
        // this class.
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Command/' . $class . '.php');
        self::assertStringContainsString("getOption('width')", $src, $class . ' must thread --width into its model');
    }

    public function testHintFootersRenderWhileInteractive(): void
    {
        $choose = ChooseModel::fromOptions(['a'])->view();
        self::assertStringContainsString(Lang::t('hints.choose'), $choose);

        $filter = FilterModel::fromOptions(options: ['a'])->view();
        self::assertStringContainsString(Lang::t('hints.filter'), $filter);

        $pager = PagerModel::fromContent('body')->view();
        self::assertStringContainsString(Lang::t('hints.pager'), $pager);
    }

    public function testHintFootersDisappearOnceResolved(): void
    {
        // Submitting must strip the footer so scripted byte captures of the
        // final frame stay clean; a resize alone keeps the view interactive.
        [$resized] = ChooseModel::fromOptions(['a'])->update(new WindowSizeMsg(80, 24));
        self::assertStringContainsString(Lang::t('hints.choose'), $resized->view(), 'resize alone stays interactive');

        [$submitted] = ChooseModel::fromOptions(['a'])->update(new KeyMsg(KeyType::Enter));
        self::assertStringNotContainsString(Lang::t('hints.choose'), $submitted->view());
    }
}
