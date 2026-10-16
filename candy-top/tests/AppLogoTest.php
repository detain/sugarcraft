<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Animation\ArtLoop;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\LogoTickMsg;
use SugarCraft\Top\Overlay\MainMenu;
use SugarCraft\Top\Overlay\SignalMenu;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\View\Logo;

/**
 * The logo lifecycle inside the root Model: pick at start (never armed), a
 * per-open re-roll at the withOverlay/replaceTop choke (random changes each
 * entry into a banner menu, explicit slugs replay identically, non-logo
 * pushes and same-class navigation never re-roll), arm only via the clock
 * sweep while a logo overlay is open, per-art elapsed frames, disarm
 * without residue on close, and a fresh frame-zero pick when the colour
 * profile flips.
 */
final class AppLogoTest extends TestCase
{
    private ?string $tz = null;

    protected function setUp(): void
    {
        T::reset();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        putenv(Logo::CHOICE_ENV . '=rock-candy-prism');
        Logo::resetForTesting();
    }

    protected function tearDown(): void
    {
        putenv(Logo::CHOICE_ENV . '=off');
        putenv(Logo::SEED_ENV);
        Logo::resetForTesting();
        date_default_timezone_set($this->tz ?? 'UTC');
    }

    public function testStartPicksAnimatedArtButNeverArms(): void
    {
        $app = Harness::app();
        $this->assertNotNull($app->logoArt);
        $this->assertTrue($app->logoArt->isAnimated());
        $this->assertSame(0, $app->logoFrame);
        $this->assertFalse($app->isLogoArmed(), 'start() must not mint a timer before the loop exists');
    }

    public function testClockSweepArmsOnlyWhileALogoOverlayIsOpen(): void
    {
        $app = self::sized(Harness::app());
        [$app, $cmd] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        $this->assertFalse($app->isLogoArmed(), 'no overlay open yet, nothing to animate');

        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        [$app, $cmd] = $app->update(new ClockTickMsg(Harness::TIME + 1.0, 3601.0));
        $this->assertTrue($app->isLogoArmed());
        $this->assertCount(1, self::logoTicks($cmd), 'exactly one logo timer per sweep');
    }

    /**
     * Flipped from the wall-phase pin: the frame clock is now per-art
     * elapsed, because an absolute wall phase jumped every freshly opened
     * logo to whatever frame the wall clock happened to sit on (the user
     * saw animations start mid-loop). armed() sweeps at TIME+1.0, which
     * stamps startedAt there — so a tick at TIME+1.35 is 0.35 s into THIS
     * art, and ArtLoop::frameAt's own fmod makes one full cycle later the
     * same frame again.
     */
    public function testLogoTickAdvancesFrameFromPerArtStartAndRearms(): void
    {
        $app = self::armed();
        $loop = ArtLoop::new($app->logoArt->frameCount());
        [$next, $cmd] = $app->update(new LogoTickMsg(0, Harness::TIME + 1.0 + 0.35));
        $this->assertSame($loop->frameAt(0.35), $next->logoFrame, '0.35 s after the arm stamps 0.35 s of elapsed');
        // A tick BEFORE the art started (in-flight from an older timeline)
        // clamps to frame 0 instead of wrapping the negative into a late frame.
        [$early] = $app->update(new LogoTickMsg(0, Harness::TIME + 0.5));
        $this->assertSame(0, $early->logoFrame);
        // The cycle wraps through ArtLoop's own modulo, not a wall fmod.
        [$wrapped] = $app->update(new LogoTickMsg(0, Harness::TIME + 1.0 + $loop->cycleSeconds() + 0.35));
        $this->assertSame($loop->frameAt(0.35), $wrapped->logoFrame);
        $this->assertTrue($next->isLogoArmed());
        $this->assertNotEmpty(self::logoTicks($cmd), 'the tick re-arms like Clock/Data ticks');
    }

    public function testTickDisarmsSilentlyOnceTheOverlayCloses(): void
    {
        $app = self::armed();
        [$closed] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->overlay(), 'escape closes the main menu');
        [$after, $cmd] = $closed->update(new LogoTickMsg(0, 1_700_000_000.5));
        $this->assertNull($cmd, 'a closed overlay must leave no re-armed timer');
        $this->assertFalse($after->isLogoArmed());
    }

    public function testProfileFlipRepicksAtFrameZeroAndRetiresTheOldEpoch(): void
    {
        $app = self::armed();
        [$app, $tick] = $app->update(new LogoTickMsg(0, 1_700_000_000.35));
        // applyConfig fires between ticks and must stamp the fresh start from
        // LIVE wall time, not the (up to ~1 s stale, here 3 years stale via
        // Harness::TIME) $this->now it carries — a stale stamp would start
        // the new art's elapsed clock in the past and skip frames.
        $wallBefore = microtime(true);
        [$next, $cmd] = $app->applyConfig(self::ttyConfig(), false);
        $this->assertNotSame($app->logoArt, $next->logoArt, 'depth change must re-pick');
        $this->assertSame(0, $next->logoFrame, 'the new art starts at frame zero');
        $startedAt = (new \ReflectionProperty($next, 'logoStartedAt'))->getValue($next);
        $this->assertGreaterThanOrEqual($wallBefore, $startedAt, 're-pick stamps live wall time');
        $this->assertGreaterThan((float) $app->now, $startedAt, 'never the stale tick-carried now');
        $this->assertTrue($next->isLogoArmed());
        $ticks = self::logoTicks($cmd);
        $this->assertCount(1, $ticks);
        $this->assertSame(1, ($ticks[0]->produce)()->epoch, 'the re-armed tick carries the new epoch');
        // Anything already in flight on the old epoch retires on arrival.
        [$stale, $staleCmd] = $next->update(new LogoTickMsg(0, 1_700_000_000.4));
        $this->assertSame($next, $stale);
        $this->assertNull($staleCmd);
    }

    public function testApplyConfigWithNoLogoOverlayOpenDoesNotArmTheTick(): void
    {
        // The pick itself is profile-driven and stays useful for the next
        // paint, but arming a repeating tick while nothing displays the logo
        // only burns wakeups — the ClockTick sweep re-arms the moment a logo
        // menu opens.
        $app = self::sized(Harness::app());
        [$next, $cmd] = $app->applyConfig(self::ttyConfig(), false);
        $this->assertNotNull($next->logoArt, 'the pick still happens for later paints');
        $this->assertFalse($next->isLogoArmed(), 'no open logo menu means no armed tick');
        $this->assertSame([], self::logoTicks($cmd));
    }

    public function testEnvOffKeepsTheLegacyBannerPath(): void
    {
        putenv(Logo::CHOICE_ENV . '=off');
        Logo::resetForTesting();
        $app = Harness::app();
        $this->assertNull($app->logoArt);
        $this->assertNull($app->overlayContext()->logo);
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        $this->assertFalse($app->isLogoArmed(), 'nothing to sweep-arm when the logo is off');
    }

    public function testEveryOpenReRollsTheRandomLogoUnderASeed(): void
    {
        putenv(Logo::CHOICE_ENV . '=random');
        putenv(Logo::SEED_ENV . '=42');
        Logo::resetForTesting();
        $app = self::sized(Harness::app());
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'o'));
        $first = $app->logoArt;
        $this->assertNotNull($first);
        [$app] = $app->update(new KeyMsg(KeyType::Escape));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'o'));
        $second = $app->logoArt;
        $this->assertNotNull($second);
        // Seed 42's tc draw order is chocolate-bar-emboss, licorice-allsorts,
        // twilight-candyland-hills — open #2 and #3 land on different slugs.
        $this->assertNotSame($first->sgrText(0), $second->sgrText(0), 'each open advances the draw');
        $this->assertSame(3, Logo::picksForTesting(), 'start pick + one draw per open');
    }

    public function testSeedFixesThePerOpenSequenceNotASingleLogo(): void
    {
        $sequence = function (): array {
            putenv(Logo::CHOICE_ENV . '=random');
            putenv(Logo::SEED_ENV . '=42');
            Logo::resetForTesting();
            $app = self::sized(Harness::app());
            $arts = [$app->logoArt?->sgrText(0)];
            [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
            $arts[] = $app->logoArt?->sgrText(0);
            [$app] = $app->update(new KeyMsg(KeyType::Escape));
            [$app] = $app->update(new KeyMsg(KeyType::Char, 'f1'));
            $arts[] = $app->logoArt?->sgrText(0);
            return $arts;
        };
        $this->assertSame($sequence(), $sequence(), 'the seed replays the whole open sequence');
        $run = $sequence();
        $this->assertNotSame($run[0], $run[1]);
        $this->assertNotSame($run[1], $run[2], 'successive opens are distinct, not one frozen logo');
    }

    public function testNonLogoPushKeepsTheArtAndPopRevealReRollsIt(): void
    {
        putenv(Logo::CHOICE_ENV . '=random');
        putenv(Logo::SEED_ENV . '=42');
        Logo::resetForTesting();
        $app = self::sized(Harness::app());
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        $atMenu = $app->logoArt;
        $stacked = $app->withOverlay(SignalMenu::new(4410, 'node', FakeProcessControl::new()));
        $this->assertSame($atMenu, $stacked->logoArt, 'covering a menu with the signal chooser is not an entry into a logo page');
        $this->assertSame($app->logoFrame, $stacked->logoFrame, 'a no-re-pick churns no frame state');
        [$revealed] = $stacked->update(new KeyMsg(KeyType::Escape));
        $this->assertInstanceOf(MainMenu::class, $revealed->overlay(), 'closing the chooser re-reveals the main menu');
        $this->assertNotSame($atMenu?->sgrText(0), $revealed->logoArt?->sgrText(0), 're-entry to a logo page re-rolls');
    }

    public function testOffModeReOpenIsANoOp(): void
    {
        putenv(Logo::CHOICE_ENV . '=off');
        Logo::resetForTesting();
        $app = self::sized(Harness::app());
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        [$app, $cmd] = $app->update(new ClockTickMsg(Harness::TIME + 1.0, 3601.0));
        $this->assertNull($app->logoArt, 'off stays off across an open');
        $this->assertFalse($app->isLogoArmed(), 'a null art arms nothing');
        $this->assertSame([], self::logoTicks($cmd));
        $this->assertSame(0, Logo::picksForTesting(), 'off resolves before any draw');
    }

    public function testExplicitSlugReplaysIdenticallyOnEveryOpen(): void
    {
        // setUp env pins rock-candy-prism; the goldens run under an explicit
        // slug too, so identity across re-opens is the golden contract.
        $app = self::sized(Harness::app());
        $start = $app->logoArt;
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame($start, $app->logoArt, 'the memo answers slug re-opens with the same instance');
        [$app] = $app->update(new KeyMsg(KeyType::Escape));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame($start, $app->logoArt);
        $this->assertSame(0, $app->logoFrame, 'an identity re-pick churns no state');
    }

    private static function ttyConfig(): Config
    {
        return Config::new()->with('color_theme', 'Default')->with('tty_mode', true);
    }

    private static function sized(App $app): App
    {
        [$app] = $app->update(new WindowSizeMsg(120, 40));

        return $app;
    }

    /** @return list<TickRequest> */
    private static function logoTicks(?\Closure $cmd): array
    {
        return array_values(array_filter(
            Cmds::of(TickRequest::class, $cmd),
            static fn (TickRequest $t): bool => ($t->produce)() instanceof LogoTickMsg,
        ));
    }

    private static function armed(): App
    {
        $app = self::sized(Harness::app());
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'm'));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME + 1.0, 3601.0));
        self::assertTrue($app->isLogoArmed());

        return $app;
    }
}
