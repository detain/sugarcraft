<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

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

    public function testPagerResolvesTerminalSizeAndReservesTheHintRow(): void
    {
        Geometry::override(['cols' => 120, 'rows' => 40]);
        $pager = PagerModel::fromContent('body');
        self::assertSame(120, $pager->viewport->width);
        self::assertSame(39, $pager->viewport->height, 'auto height reserves the footer row');

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
        self::assertSame(49, $next->viewport->height, 'scriptui-20: the resize re-fit keeps the footer reservation');
        self::assertFalse($next->isExited());

        // Already-sized model keeps identity (no repaint churn per tick).
        [$same, $sameCmd] = $next->update(new WindowSizeMsg(150, 50));
        self::assertSame($next, $same);
        self::assertNull($sameCmd);
    }

    public function testDumbTerminalsNeverGetAltScreenOrHiddenCursors(): void
    {
        putenv('TERM=dumb');
        $options = InteractiveRuntime::programOptions(useAltScreen: true, hideCursor: true);
        if ($options === null) {
            $this->markTestSkipped('no controlling terminal for this child probe path');
        }
        self::assertFalse($options->useAltScreen);
        self::assertFalse($options->hideCursor);
        self::assertTrue($options->inlineMode);
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
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Command/' . $class . '.php');
        self::assertStringContainsString("addOption('no-alt-screen'", $src);
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
