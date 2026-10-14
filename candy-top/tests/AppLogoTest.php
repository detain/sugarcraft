<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Flip\ArtLoop;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\LogoTickMsg;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\View\Logo;

/**
 * The logo lifecycle inside the root Model: pick at start (never armed),
 * arm only via the clock sweep while a logo overlay is open, absolute
 * wall-phase frames, disarm without residue on close, and a fresh
 * frame-zero pick when the colour profile flips.
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

    public function testLogoTickAdvancesFrameByWallPhaseAndRearms(): void
    {
        $app = self::armed();
        $time = 1_700_000_000.35;
        [$next, $cmd] = $app->update(new LogoTickMsg(0, $time));
        $loop = ArtLoop::new($app->logoArt->frameCount());
        $this->assertSame($loop->frameAt(fmod($time, $loop->cycleSeconds())), $next->logoFrame);
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
        [$next, $cmd] = $app->applyConfig(self::ttyConfig(), false);
        $this->assertNotSame($app->logoArt, $next->logoArt, 'depth change must re-pick');
        $this->assertSame(0, $next->logoFrame, 'the new art starts at frame zero');
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
