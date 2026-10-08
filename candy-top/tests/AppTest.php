<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Msg\UpdateStepMsg;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\PlaceholderPanel;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\Tests\Support\ClaimingPanel;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\SizeError;
use SugarCraft\Top\View\Surface;

/**
 * The root Model: cadence (clock + data ticks re-armed in update()),
 * quit, resize reflow, the size gate, box toggles, and the frame
 * invariants (exactly rows x cols, every frame size and box combo).
 */
final class AppTest extends TestCase
{
    /** 2026-10-08 11:22:33 UTC + 0.25 s. */
    private const NOW = 1_791_458_553.25;

    private ?string $tz = null;

    protected function setUp(): void
    {
        T::reset();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz ?? 'UTC');
    }

    public function testInitBatchesClockAllShownSamplesAndOneDataTick(): void
    {
        $msgs = Cmds::run(self::app()->init());

        $this->assertCount(1, array_filter($msgs, static fn (Msg $m): bool => $m instanceof ClockTickMsg));
        $boxes = array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, self::app()->init()));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $boxes);
        $ticks = Cmds::of(TickRequest::class, self::app()->init());
        $this->assertCount(1, $ticks);
        $this->assertSame(2.0, $ticks[0]->seconds, 'update_ms default 2000');
        $this->assertEquals(new DataTickMsg(0), ($ticks[0]->produce)());
    }

    public function testHiddenBoxesAreNeverSampledNorPainted(): void
    {
        // btop #1858: a box missing from shown_boxes must not build or render.
        $app = self::sized(self::app(self::config(['shown_boxes' => 'cpu proc'])), 100, 30);
        $sampled = array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $app->init()));
        $this->assertSame(['cpu', 'proc'], $sampled);

        [$app] = $app->update(new DataTickMsg(0));
        $plain = implode("\n", $app->surface()?->plainLines() ?? []);
        $this->assertStringContainsString('cpu', $plain);
        $this->assertStringContainsString('proc', $plain);
        $this->assertStringNotContainsString('mem', $plain);
        $this->assertStringNotContainsString('net', $plain);
        $this->assertStringNotContainsString('disks', $plain);
    }

    public function testDataTickReArmsAndResamplesEveryShownBox(): void
    {
        $app = self::sized(self::app(), 120, 40);
        [$next, $cmd] = $app->update(new DataTickMsg(0));

        $this->assertSame($app, $next);
        $ticks = Cmds::of(TickRequest::class, $cmd);
        $this->assertCount(1, $ticks, 'one-shot tick re-armed unconditionally');
        $this->assertSame(2.0, $ticks[0]->seconds);
        $this->assertCount(4, Cmds::of(SampledMsg::class, $cmd));
    }

    public function testPlusMinusChangeThePeriodAndOutdateTheArmedTick(): void
    {
        $app = self::sized(self::app(), 120, 40);
        [$pressed, $stepCmd] = $app->update(new KeyMsg(KeyType::Char, '-'));
        $this->assertSame(2000, $pressed->config->updateMs(), 'the step lands via its timestamped Msg');
        $steps = Cmds::of(UpdateStepMsg::class, $stepCmd);
        $this->assertCount(1, $steps);
        [$faster, $cmd] = $pressed->update($steps[0]);
        $this->assertSame(1900, $faster->config->updateMs());
        $this->assertSame(1, $faster->generation);
        $this->assertSame(1.9, Cmds::of(TickRequest::class, $cmd)[0]->seconds);

        // The tick armed under generation 0 still fires — and is ignored.
        [$same, $stale] = $faster->update(new DataTickMsg(0));
        $this->assertSame($faster, $same);
        $this->assertNull($stale);

        $slower = self::press($faster, '+');
        $this->assertSame(2000, $slower->config->updateMs());
        $this->assertStringContainsString('2000ms', $slower->surface()?->plainLines()[0] ?? '');
    }

    public function testMinusStopsAtBtopFloor(): void
    {
        $app = self::sized(self::app(self::config(['update_ms' => 100])), 120, 40);
        [$pressed, $stepCmd] = $app->update(new KeyMsg(KeyType::Char, '-'));
        [$next, $cmd] = $pressed->update(Cmds::of(UpdateStepMsg::class, $stepCmd)[0]);
        $this->assertSame(100, $next->config->updateMs());
        $this->assertNull($cmd);
    }

    public function testHeldPlusStepsAThousandAfterAFullHistoryOfFastPresses(): void
    {
        // btop: the 1000 ms step needs all 50 history keys to be `+` and the
        // previous step < 200 ms ago. 50 ms between presses here.
        $app = self::sized(self::app(null, self::steppingClock(0.05)), 120, 40);
        for ($i = 1; $i < App::HOLD_HISTORY; $i++) {
            $app = self::press($app, '+');
        }
        $this->assertSame(2000 + 49 * 100, $app->config->updateMs(), 'history not yet all `+`');
        $app = self::press($app, '+');
        $this->assertSame(6900 + 1000, $app->config->updateMs(), '50th consecutive `+` accelerates');
        $app = self::press($app, '+');
        $this->assertSame(8900, $app->config->updateMs());

        // Any other key breaks the run: back to 100 ms steps.
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $app = self::press($app, '+');
        $this->assertSame(9000, $app->config->updateMs());
    }

    public function testHeldKeysPressedSlowlyNeverAccelerate(): void
    {
        $app = self::sized(self::app(self::config(['update_ms' => 9000]), self::steppingClock(0.5)), 120, 40);
        for ($i = 0; $i < App::HOLD_HISTORY + 5; $i++) {
            $app = self::press($app, '-');
        }
        $this->assertSame(9000 - 55 * 100, $app->config->updateMs());
    }

    public function testHeldMinusFallsBackToSmallStepsBelowTwoSeconds(): void
    {
        $app = self::sized(self::app(self::config(['update_ms' => 2500]), self::steppingClock(0.01)), 120, 40);
        for ($i = 0; $i < App::HOLD_HISTORY; $i++) {
            $app = self::press($app, '-');
        }
        // 49 × 100 would undershoot the floor; steps stop at btop's 100 floor.
        $this->assertSame(100, $app->config->updateMs());

        $app = self::sized(self::app(self::config(['update_ms' => 7000]), self::steppingClock(0.01)), 120, 40);
        for ($i = 0; $i < App::HOLD_HISTORY + 3; $i++) {
            $app = self::press($app, '-');
        }
        // 49 small steps (7000 → 2100), then the fast step needs >= 2000:
        // 2100 → 1100; 1100 < 2000 → small steps 1000, 900, 800.
        $this->assertSame(800, $app->config->updateMs());
    }

    public function testEqualsStepsButNeverAccelerates(): void
    {
        $app = self::sized(self::app(null, self::steppingClock(0.01)), 120, 40);
        for ($i = 0; $i < App::HOLD_HISTORY + 2; $i++) {
            $app = self::press($app, '=');
        }
        $this->assertSame(2000 + 52 * 100, $app->config->updateMs());
    }

    public function testApplyConfigReArmsTheTickAndSamplesNewlyShownBoxes(): void
    {
        $app = self::booted(120, 40, self::config(['shown_boxes' => 'cpu mem']));
        [$next, $cmd] = $app->applyConfig($app->config->withUpdateMs(1500)->with('shown_boxes', 'cpu mem proc'));
        $this->assertSame(1, $next->generation);
        $ticks = Cmds::of(TickRequest::class, $cmd);
        $this->assertCount(1, $ticks, 'the new generation is armed — the old tick is now stale');
        $this->assertSame(1.5, $ticks[0]->seconds);
        $sampled = Cmds::of(SampledMsg::class, $cmd);
        $this->assertSame(['proc'], array_map(static fn (SampledMsg $m): string => $m->box, $sampled));

        [$same, $none] = $next->applyConfig($next->config);
        $this->assertSame(1, $same->generation);
        $this->assertNull($none, 'nothing changed: nothing to arm or sample');
    }

    public function testClockTickStoresTimeAndReArmsOnTheNextSecond(): void
    {
        $app = self::sized(self::app(), 120, 40);
        [$next, $cmd] = $app->update(new ClockTickMsg(self::NOW, 90_061.0));

        $this->assertSame(self::NOW, $next->now);
        $this->assertSame('11:22:33', $next->clockText());
        $ticks = Cmds::of(TickRequest::class, $cmd);
        $this->assertCount(1, $ticks);
        $this->assertEqualsWithDelta(0.75, $ticks[0]->seconds, 1e-9, 'aligned to the wall-clock second');
        $this->assertInstanceOf(ClockTickMsg::class, ($ticks[0]->produce)());
    }

    public function testClockIsEmbeddedCentredOnTheCpuTopBorder(): void
    {
        $app = self::booted(120, 40);
        $top = $app->surface()?->plainLines()[0] ?? '';
        $pos = mb_strpos($top, '11:22:33');
        $this->assertNotFalse($pos);
        // btop update_clock: the opening junction at 1-based
        // x + width/2 - len/2 = 57, i.e. 0-based 56; the text follows it.
        $this->assertSame(57, $pos);
        $this->assertSame('┐', mb_substr($top, 56, 1));
        $this->assertSame('┌', mb_substr($top, 65, 1));
    }

    public function testClockFormatCustomTokensAndEmptyDisables(): void
    {
        $app = self::booted(120, 40, self::config(['clock_format' => '/user@/host /uptime']));
        // btop drops the seconds once a day count pushes /uptime past 8 chars.
        $this->assertSame('joe@box 1d 01:01', $app->clockText());

        $off = self::booted(120, 40, self::config(['clock_format' => '']));
        $this->assertSame('', $off->clockText());
        $this->assertStringNotContainsString('11:22', $off->surface()?->plainLines()[0] ?? '');
    }

    /** @return iterable<string, array{KeyMsg}> */
    public static function quitKeys(): iterable
    {
        yield 'q' => [new KeyMsg(KeyType::Char, 'q')];
        yield 'ctrl+c' => [new KeyMsg(KeyType::Char, 'c', ctrl: true)];
    }

    #[DataProvider('quitKeys')]
    public function testQuitKeysReturnTheQuitCmd(KeyMsg $key): void
    {
        [, $cmd] = self::booted(120, 40)->update($key);
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testQuitStillWorksBehindTheSizeNotice(): void
    {
        [, $cmd] = self::booted(50, 15)->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(QuitMsg::class, $cmd?->__invoke());
    }

    public function testViewIsEmptyBeforeTheFirstWindowSize(): void
    {
        $this->assertSame('', self::app()->view());
        $this->assertNull(self::app()->surface());
    }

    public function testTooSmallTerminalShowsBtopNotice(): void
    {
        $app = self::booted(70, 20);
        $this->assertSame(SizeError::render(70, 20, 80, 24), $app->view());
        $plain = $app->surface()?->plainLines() ?? [];
        $this->assertCount(20, $plain);
        $this->assertStringContainsString('Terminal size too small:', $plain[7]);
        $this->assertStringContainsString('Width = 80 Height = 24', $plain[11]);
    }

    public function testResizeReflowsTheLayoutAndSeams(): void
    {
        $app = self::booted(120, 40);
        $this->assertSame(54, $app->layout?->box('proc')?->x);

        [$wide] = $app->update(new WindowSizeMsg(200, 60));
        $this->assertSame(90, $wide->layout?->box('proc')?->x);
        $plain = $wide->surface()?->plainLines() ?? [];
        $this->assertCount(60, $plain);
        // The mem|proc seam moved with the layout: mem's right edge at col 89.
        $this->assertSame('╮', mb_substr($plain[20], 89, 1));
        $this->assertSame('╭', mb_substr($plain[20], 90, 1));

        [$small] = $wide->update(new WindowSizeMsg(70, 20));
        $this->assertStringContainsString('Terminal size too small:', $small->view());

        [$back] = $small->update(new WindowSizeMsg(80, 24));
        $this->assertSame(36, $back->layout?->box('proc')?->x);
        $this->assertCount(24, $back->surface()?->plainLines() ?? []);
    }

    public function testNegativeOrZeroSizeIsCoerced(): void
    {
        [$app] = self::app()->update(new WindowSizeMsg(-5, 0));
        $this->assertSame(0, $app->cols);
        $this->assertSame('', $app->view());
    }

    public function testBoxToggleHidesAShownBoxAndDropsThePreset(): void
    {
        $app = self::booted(120, 40);
        [$noProc, $hideCmd] = $app->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertNull($hideCmd, 'hiding samples nothing');
        $this->assertSame(['cpu', 'mem', 'net'], $noProc->config->shownBoxes());
        $this->assertNull($noProc->layout?->box('proc'));
        $this->assertSame(120, $noProc->layout?->box('mem')?->width);

        [$again] = $noProc->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $again->config->shownBoxes());
    }

    public function testBoxToggledOnIsSampledImmediately(): void
    {
        // btop runs Runner::run("all", false, true) right after toggle_box.
        $app = self::booted(120, 40, self::config(['shown_boxes' => 'cpu mem']));
        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(['cpu', 'mem', 'proc'], $next->config->shownBoxes());
        $sampled = Cmds::of(SampledMsg::class, $cmd);
        $this->assertCount(1, $sampled);
        $this->assertSame('proc', $sampled[0]->box);
        $this->assertSame([], Cmds::of(TickRequest::class, $cmd), 'the cadence is untouched');

        [$painted] = $next->update($sampled[0]);
        $proc = $painted->panel('proc');
        $this->assertInstanceOf(PlaceholderPanel::class, $proc);
        $this->assertNotNull($proc->snapshot());
    }

    public function testVisiblePanelClaimsKeysAheadOfGlobalKeys(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', ['+', '-', '=']);
        $panels['cpu'] = new ClaimingPanel('cpu', []);
        $app = self::sized(self::app(null, null, $panels), 120, 40);
        $seenBefore = count($app->panel('proc')->seen); // the WindowSizeMsg broadcast

        foreach (['+', '-', '='] as $rune) {
            [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertNull($cmd, "{$rune} claimed: no step, no sample");
        }
        $this->assertSame(2000, $app->config->updateMs());
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ClaimingPanel::class, $proc);
        $this->assertCount($seenBefore + 3, $proc->seen, 'the captor receives each key');
        $this->assertSame(['+', '-', '='], array_map(static fn ($m): string => $m->string(), array_slice($proc->seen, $seenBefore)));
        $this->assertCount($seenBefore, $app->panel('cpu')->seen, 'claimed keys are not broadcast to the others');

        // Unclaimed keys still reach the globals and the broadcast.
        [, $quit] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($quit);
        [$after] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertCount($seenBefore + 4, $after->panel('proc')->seen);

        // ctrl+c is never offered.
        [, $quit] = $app->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertInstanceOf(QuitMsg::class, $quit());
    }

    public function testClaimsCannotTakeQuitOrBoxToggles(): void
    {
        // A claiming (non-modal) panel that claims everything still cannot
        // disable `q` or `1`-`4`: those are never offered to capturesKey().
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['cpu'] = new ClaimingPanel('cpu', ['q', '1', '2', '3', '4', '+']);
        $app = self::sized(self::app(null, null, $panels), 120, 40);

        foreach (['4' => 'cpu mem net', '3' => 'cpu mem', '2' => 'cpu'] as $rune => $shown) {
            [$app] = $app->update(new KeyMsg(KeyType::Char, (string) $rune));
            $this->assertSame(explode(' ', $shown), $app->config->shownBoxes(), "{$rune} toggled despite the claim");
        }
        [$app] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(['cpu'], $app->config->shownBoxes(), 'the last box cannot be toggled off');
        [$app] = $app->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(['cpu', 'mem'], $app->config->shownBoxes());
        [$app] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(['mem'], $app->config->shownBoxes(), '1 toggled cpu despite its own claim');
        [$app] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(['mem', 'cpu'], $app->config->shownBoxes(), 'btop appends a re-shown box');

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'q quits despite the claim');

        $cpu = $app->panel('cpu');
        $this->assertInstanceOf(ClaimingPanel::class, $cpu);
        $keys = array_values(array_filter($cpu->seen, static fn ($m): bool => $m instanceof KeyMsg));
        $this->assertSame([], array_map(static fn (KeyMsg $m): string => $m->string(), $keys), 'globals are consumed, never delivered');
        [$app] = $app->update(new KeyMsg(KeyType::Char, '+'));
        $this->assertCount(1, array_filter($app->panel('cpu')->seen, static fn ($m): bool => $m instanceof KeyMsg), 'other claims still work');
    }

    public function testHiddenOrSizeGatedPanelsAreNotOfferedKeys(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', ['q']);

        $hidden = self::sized(self::app(self::config(['shown_boxes' => 'cpu mem']), null, $panels), 120, 40);
        [, $cmd] = $hidden->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'hidden proc cannot swallow q');

        $tiny = self::sized(self::app(null, null, $panels), 20, 5);
        [, $cmd] = $tiny->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'behind the size notice only globals apply');
    }

    public function testPanelConfigWritesApplyBeforeTheNextKeyWithoutRunningCmds(): void
    {
        // candy-core's Program dispatches every key of one read before any Cmd
        // runs, so the keys below are fed back-to-back with NO Cmd executed in
        // between — btop's Config::set is immediate (btop_input.cpp:303-391).
        // proc `e` writes proc_tree and, with it on, the proc box claims `+`
        // (btop_input.cpp:491). The two halves live in different panels so the
        // claim can only flip through the App's config, not panel state.
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClaimingPanel('net', ['e', 'a'], sets: [
            'e' => static fn (Config $c): array => ['proc_tree' => !$c->bool('proc_tree')],
            'a' => static fn (Config $c): array => ['net_auto' => !$c->bool('net_auto')],
        ]);
        $panels['proc'] = new ClaimingPanel('proc', ['+'], claimWhen: 'proc_tree');
        $app = self::sized(self::app(null, null, $panels), 120, 40);
        $this->assertTrue($app->config->bool('net_auto'));

        // `a` twice in one read: toggled twice, back to the original value.
        [$app, $first] = $app->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertFalse($app->config->bool('net_auto'), 'applied inside the same update()');
        $this->assertNull($first, 'no Cmd round-trip carries the write');
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($app->config->bool('net_auto'), 'second toggle saw the first');
        $net = $app->panel('net');
        $this->assertInstanceOf(ClaimingPanel::class, $net);
        $this->assertSame([true, false], array_map(static fn (Config $c): bool => $c->bool('net_auto'), array_slice($net->configs, -2)));

        // `e` then `+` in one read: `+` already goes to the tree capture.
        $seen = count($app->panel('proc')->seen);
        [$app, $eCmd] = $app->update(new KeyMsg(KeyType::Char, 'e'));
        [$app, $plusCmd] = $app->update(new KeyMsg(KeyType::Char, '+'));
        $this->assertNull($eCmd);
        $this->assertNull($plusCmd, 'no global UpdateStepMsg: the proc panel owns `+`');
        $this->assertTrue($app->config->bool('proc_tree'));
        $this->assertSame(2000, $app->config->updateMs());
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ClaimingPanel::class, $proc);
        $this->assertCount($seen + 1, $proc->seen);
        $this->assertTrue($proc->contexts[array_key_last($proc->contexts)]->config->bool('proc_tree'), 'update() receives the applied config');

        // `e` again hands `+` straight back to the global step.
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertFalse($app->config->bool('proc_tree'));
        $this->assertSame(2100, self::press($app, '+')->config->updateMs());
    }

    public function testPanelWritesAreValidatedOneByOneAndKeepApplyConfigSideEffects(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClaimingPanel('net', ['z'], sets: [
            'z' => static fn (): array => ['proc_sorting' => 'bogus', 'update_ms' => 1500, 'no_such_option' => true],
        ]);
        $app = self::sized(self::app(self::config(['shown_boxes' => 'cpu mem net']), null, $panels), 120, 40);

        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'z'));
        $this->assertSame(1500, $next->config->updateMs(), 'the valid write applies');
        $this->assertSame($app->config->string('proc_sorting'), $next->config->string('proc_sorting'), 'a value btop rejects is dropped');
        $this->assertSame(1, $next->generation, 'applyConfig outdated the armed tick');
        $this->assertSame([1.5], array_map(static fn (TickRequest $t): float => $t->seconds, Cmds::of(TickRequest::class, $cmd)));
    }

    public function testAsyncSetOptionMsgFromACmdStillRoutesThroughApplyConfig(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['net'] = new ClaimingPanel('net', ['e'], emits: [
            'e' => static fn (Config $c): SetOptionMsg => new SetOptionMsg('proc_tree', !$c->bool('proc_tree')),
        ]);
        $panels['proc'] = new ClaimingPanel('proc', ['+'], claimWhen: 'proc_tree');
        $app = self::sized(self::app(null, null, $panels), 120, 40);

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertFalse($app->config->bool('proc_tree'), 'an async write waits for its Cmd');
        $set = Cmds::of(SetOptionMsg::class, $cmd);
        $this->assertCount(1, $set);
        [$app, $applied] = $app->update($set[0]);
        $this->assertTrue($app->config->bool('proc_tree'));
        $this->assertNull($applied, 'no period or box change: nothing to arm or sample');
        [, $claimed] = $app->update(new KeyMsg(KeyType::Char, '+'));
        $this->assertNull($claimed);
    }

    public function testModalPanelOwnsEveryKeyAndOnlyClicks(): void
    {
        // btop checks proc_filtering before everything (btop_input.cpp:217-222,
        // 301-342) and drops every mouse event but a click (158-161).
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['cpu'] = new ClaimingPanel('cpu', ['q', '1', '+', 'escape']);
        $panels['proc'] = new ClaimingPanel('proc', [], modalWhen: 'proc_filtering', sets: [
            'escape' => static fn (): array => ['proc_filtering' => false],
        ]);
        $app = self::sized(self::app(null, null, $panels), 120, 40);
        [$app] = $app->update(new SetOptionMsg('proc_filtering', true));
        $cpuSeen = count($app->panel('cpu')->seen);
        $procSeen = count($app->panel('proc')->seen);

        foreach (['q', '1', '4', '+', '-', 'x'] as $rune) {
            [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertNull($cmd, "{$rune}: no quit, toggle or step while modal");
        }
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $app->config->shownBoxes());
        $this->assertSame(2000, $app->config->updateMs());

        [$app] = $app->update(new MouseWheelMsg(10, 30, MouseButton::WheelUp, MouseAction::Press));
        [$app] = $app->update(new MouseMotionMsg(10, 30, MouseButton::Left, MouseAction::Motion));
        // candy-core emits MouseClickMsg for every press; btop's filter only
        // passes the bare left press `[<0;...M`.
        [$app] = $app->update(new MouseClickMsg(10, 30, MouseButton::Right, MouseAction::Press));
        [$app] = $app->update(new MouseClickMsg(10, 30, MouseButton::Middle, MouseAction::Press));
        [$app] = $app->update(new MouseClickMsg(10, 30, MouseButton::Left, MouseAction::Press, shift: true));
        [$app] = $app->update(new MouseClickMsg(10, 30, MouseButton::Left, MouseAction::Press, ctrl: true));
        [$app] = $app->update(new MouseClickMsg(10, 30, MouseButton::Left, MouseAction::Press));

        $proc = $app->panel('proc');
        $cpu = $app->panel('cpu');
        $this->assertInstanceOf(ClaimingPanel::class, $proc);
        $this->assertInstanceOf(ClaimingPanel::class, $cpu);
        $this->assertCount($cpuSeen, $cpu->seen, 'nothing reaches the other panels, claims included');
        $got = array_slice($proc->seen, $procSeen);
        $this->assertCount(7, $got, 'six keys plus the bare left click; wheel, motion, right/middle and modified presses are dropped');
        $this->assertInstanceOf(MouseClickMsg::class, $got[6]);
        $this->assertSame(MouseButton::Left, $got[6]->button);
        $this->assertFalse($got[6]->shift);

        // ctrl+c still quits.
        [, $quit] = $app->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertInstanceOf(QuitMsg::class, $quit());

        // The modal panel closes itself synchronously; the very next key is
        // offered to the claims again (cpu takes `+`).
        [$app] = $app->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($app->config->bool('proc_filtering'));
        [$app] = $app->update(new KeyMsg(KeyType::Char, '+'));
        $this->assertCount($cpuSeen + 1, $app->panel('cpu')->seen, 'claims are back once the modal closed');
        $this->assertCount($procSeen + 8, $app->panel('proc')->seen, 'escape went to the modal panel, + did not');
    }

    public function testHiddenModalPanelDoesNotOwnInput(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', [], modalWhen: 'proc_filtering');
        $app = self::sized(self::app(self::config(['shown_boxes' => 'cpu mem net']), null, $panels), 120, 40);
        [$app] = $app->update(new SetOptionMsg('proc_filtering', true));
        [, $quit] = $app->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(QuitMsg::class, $quit());
    }

    public function testSizeNoticeOnlyHonoursQuitAndBoxToggles(): void
    {
        // btop.cpp:180-198: the size-notice loop reads `q` and `1`-`4` only.
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', [], modalWhen: 'proc_filtering');
        $tiny = self::sized(self::app(self::config(['proc_filtering' => true]), null, $panels), 70, 24);
        $this->assertNull($tiny->context('proc')->box, 'behind the notice no panel is visible');
        $seen = count($tiny->panel('proc')->seen);

        foreach (['+', '-', '='] as $rune) {
            [$next, $cmd] = $tiny->update(new KeyMsg(KeyType::Char, $rune));
            $this->assertNull($cmd, "{$rune} does not step behind the size notice");
            $this->assertSame(2000, $next->config->updateMs());
        }
        [$next, $cmd] = $tiny->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($cmd);
        [$next] = $next->update(new MouseClickMsg(5, 5, MouseButton::Left, MouseAction::Press));
        $this->assertCount($seen, $next->panel('proc')->seen, 'keys and mouse are dropped, not broadcast');

        [$toggled] = $tiny->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(['cpu', 'mem', 'net'], $toggled->config->shownBoxes(), 'box toggles still work, modal or not');
        $this->assertSame('╭', $toggled->surface()?->glyph(0, 0), 'cpu mem net fits 70x24: framed again');
        [, $quit] = $tiny->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(QuitMsg::class, $quit());
    }

    public function testPanelContextFollowsResizeAndToggles(): void
    {
        $panels = Panels::placeholders(self::host(), self::config(), true);
        $panels['proc'] = new ClaimingPanel('proc', ['k']);
        $app = self::sized(self::app(null, null, $panels), 120, 40);

        $last = static function (App $app): \SugarCraft\Top\Panel\PanelContext {
            [$app] = $app->update(new KeyMsg(KeyType::Char, 'k'));
            $proc = $app->panel('proc');
            self::assertInstanceOf(ClaimingPanel::class, $proc);

            return $proc->contexts[array_key_last($proc->contexts)];
        };

        $ctx = $last($app);
        $this->assertSame($app->layout, $ctx->layout);
        $this->assertTrue($ctx->box?->equals($app->layout?->box('proc') ?? Rect::new(0, 0, 0, 0)));
        $this->assertSame($app->layout?->procSelectMax, $ctx->procSelectMax());

        [$wide] = $app->update(new WindowSizeMsg(200, 60));
        $ctx = $last($wide);
        $this->assertSame($wide->layout, $ctx->layout, 'resize: the new layout');
        $this->assertGreaterThan($app->layout?->procSelectMax, $ctx->procSelectMax());

        [$noCpu] = $wide->update(new KeyMsg(KeyType::Char, '1'));
        $ctx = $last($noCpu);
        $this->assertSame($noCpu->layout, $ctx->layout, 'toggle: the new layout');
        $this->assertNotSame($wide->layout, $ctx->layout);
        $this->assertSame(0, $ctx->box?->y, 'proc moved up into the freed cpu rows');
    }

    public function testSetOptionMsgKeepsApplyConfigSideEffects(): void
    {
        $app = self::booted(120, 40, self::config(['shown_boxes' => 'cpu mem']));

        [$next, $cmd] = $app->update(new SetOptionMsg('update_ms', 1500));
        $this->assertSame(1500, $next->config->updateMs());
        $this->assertSame(1, $next->generation, 'the old tick is outdated');
        $this->assertSame([1.5], array_map(static fn (TickRequest $t): float => $t->seconds, Cmds::of(TickRequest::class, $cmd)));
        [$same] = $next->update(new DataTickMsg(0));
        $this->assertSame($next, $same);

        [$shown, $cmd] = $next->update(new SetOptionMsg('shown_boxes', 'cpu mem proc'));
        $this->assertNotNull($shown->layout?->box('proc'), 'relaid out');
        $this->assertSame(['proc'], array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $cmd)));
    }

    public function testSetOptionMsgThatBtopWouldRejectIsDropped(): void
    {
        $app = self::booted(120, 40);
        foreach ([new SetOptionMsg('update_ms', 'fast'), new SetOptionMsg('no_such_option', true), new SetOptionMsg('proc_sorting', 'bogus')] as $msg) {
            [$same, $cmd] = $app->update($msg);
            $this->assertSame($app, $same);
            $this->assertNull($cmd);
        }
    }

    public function testColourProfileFollowsTruecolorAndTtyMode(): void
    {
        $app = self::sized(self::app(), 120, 40);
        $this->assertSame(ColorProfile::TrueColor, $app->ink->profile());

        [$cube] = $app->update(new SetOptionMsg('truecolor', false));
        $this->assertSame(ColorProfile::Ansi256, $cube->ink->profile());
        $this->assertSame($app->ink->palette(), $cube->ink->palette(), 'the theme is kept');
        $this->assertStringContainsString("\x1b[38;5;", $cube->view());

        [$tty] = $cube->update(new SetOptionMsg('tty_mode', true));
        $this->assertSame(ColorProfile::Ansi, $tty->ink->profile());
        [$back] = $tty->update(new SetOptionMsg('tty_mode', false));
        $this->assertSame(ColorProfile::Ansi256, $back->ink->profile());

        // A change that leaves the derived profile alone keeps an explicit start() profile.
        $forced = App::start(self::config(['truecolor' => false]), ThemeRegistry::new(null, [])->load('Default', true, false), self::host(), Panels::placeholders(self::host(), self::config(), true), null, ColorProfile::TrueColor);
        [$kept] = $forced->applyConfig($forced->config->with('proc_tree', true));
        $this->assertSame(ColorProfile::TrueColor, $kept->ink->profile());
    }

    public function testMouseEventBreaksAHeldKeyRun(): void
    {
        // btop pushes mouse events into Input::history (btop_input.cpp:193-195).
        $app = self::sized(self::app(null, self::steppingClock(0.05)), 120, 40);
        for ($i = 1; $i < App::HOLD_HISTORY; $i++) {
            $app = self::press($app, '+');
        }
        [$app] = $app->update(new MouseClickMsg(5, 5, MouseButton::Left, MouseAction::Press));
        $app = self::press($app, '+');
        $this->assertSame(2000 + 50 * 100, $app->config->updateMs(), 'the 50th `+` follows a click: no acceleration');
    }

    public function testBoxToggleRefusedWhenTheResultWouldNotFit(): void
    {
        // btop Config::toggle_box: 100x24 fits cpu+proc (min 60x24) but
        // adding mem back needs 80x24 → fits; at 70x24 it does not.
        $app = self::booted(70, 24, self::config(['shown_boxes' => 'cpu proc']));
        [$next] = $app->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(['cpu', 'proc'], $next->config->shownBoxes());
    }

    public function testBoxToggleRefusesToEmptyShownBoxes(): void
    {
        $app = self::booted(120, 40, self::config(['shown_boxes' => 'cpu']));
        [$next] = $app->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(['cpu'], $next->config->shownBoxes());
    }

    public function testSampledMsgRoutesOnlyToItsPanel(): void
    {
        $app = self::booted(120, 40);
        $panel = $app->panel('mem');
        $this->assertInstanceOf(PlaceholderPanel::class, $panel);
        $this->assertNotNull($panel->snapshot());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], array_keys($app->panels()));
    }

    public function testUnknownMsgIsBroadcastWithoutChangingTheFrame(): void
    {
        $app = self::booted(120, 40);
        [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($cmd);
        $this->assertSame($app->view(), $next->view());
    }

    /**
     * @return iterable<string, array{int, int, array<string, bool|string>}>
     */
    public static function frames(): iterable
    {
        $sizes = [[80, 24], [97, 37], [120, 40], [133, 41], [200, 60], [60, 8], [44, 16], [79, 23], [10, 3], [1, 1]];
        $combos = [
            'default' => [],
            'alternate' => ['cpu_bottom' => true, 'mem_below_net' => true, 'proc_left' => true],
            'no disks, square' => ['show_disks' => false, 'rounded_corners' => false],
            'cpu proc' => ['shown_boxes' => 'cpu proc'],
            'mem net' => ['shown_boxes' => 'mem net'],
            'cpu only' => ['shown_boxes' => 'cpu'],
            'proc only' => ['shown_boxes' => 'proc'],
        ];
        foreach ($sizes as [$cols, $rows]) {
            foreach ($combos as $name => $options) {
                yield "{$cols}x{$rows} {$name}" => [$cols, $rows, $options];
            }
        }
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('frames')]
    public function testEveryFrameIsExactlyTerminalSized(int $cols, int $rows, array $options): void
    {
        $view = self::booted($cols, $rows, self::config($options))->view();
        $this->assertIsString($view);
        $lines = explode("\n", $view);
        $this->assertCount($rows, $lines, 'frame height');
        foreach ($lines as $i => $line) {
            $this->assertSame($cols, Width::string($line), "row {$i} width");
        }
    }

    public function testChromeBytes(): void
    {
        $app = self::booted(120, 40);
        $surface = $app->surface();
        $this->assertNotNull($surface);
        $ink = $app->ink;
        // Box outline in cpu_box, number in hi_fg, title bold in title.
        $this->assertSame('╭', $surface->glyph(0, 0));
        $this->assertSame(Surface::canonical($ink->fg('cpu_box')), $surface->style(0, 0));
        $this->assertSame('¹', $surface->glyph(3, 0));
        $this->assertSame(Surface::canonical("\x1b[1m" . $ink->fg('hi_fg')), $surface->style(3, 0));
        $this->assertSame('c', $surface->glyph(4, 0));
        // Folded: the later title fg replaces hi_fg instead of stacking on it.
        $this->assertSame(Surface::canonical("\x1b[1m" . $ink->fg('title')), $surface->style(4, 0));
        // Every rendered row starts from a reset plus the theme base.
        foreach ($surface->lines() as $line) {
            $this->assertStringStartsWith("\x1b[0m" . Surface::canonical($ink->base()), $line);
            $this->assertStringEndsWith("\x1b[0m", $line);
        }
    }

    /** @param array<string, bool|int|string> $options */
    private static function config(array $options = []): Config
    {
        $config = Config::new();
        foreach ($options as $key => $value) {
            $config = $config->with($key, $value);
        }

        return $config->withShownBoxesSettled(0);
    }

    /**
     * @param ?array<string, \SugarCraft\Top\Panel\Panel> $panels
     */
    private static function app(?Config $config = null, ?\Closure $clock = null, ?array $panels = null): App
    {
        $config ??= self::config();
        $host = self::host();
        $palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), $config->bool('theme_background'), $config->ttyMode());
        $clock ??= static fn (): ClockTickMsg => new ClockTickMsg(self::NOW, 90_061.0);

        return App::start($config, $palette, $host, $panels ?? Panels::standard($host, $config, true), $clock, ColorProfile::TrueColor);
    }

    private static function host(): HostInfo
    {
        return HostInfo::new('i7-8700K', 8, 'joe', 'box');
    }

    /** A clock that advances `$step` seconds per read. */
    private static function steppingClock(float $step): \Closure
    {
        $t = self::NOW;

        return static function () use (&$t, $step): ClockTickMsg {
            $t += $step;

            return new ClockTickMsg($t, 90_061.0);
        };
    }

    /** One key press with its timestamped step fed back, as the Program would. */
    private static function press(App $app, string $rune): App
    {
        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, $rune));
        foreach (Cmds::of(UpdateStepMsg::class, $cmd) as $step) {
            [$app] = $app->update($step);
        }

        return $app;
    }

    private static function sized(App $app, int $cols, int $rows): App
    {
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));

        return $app;
    }

    /** Sized, clock set, every shown panel sampled once — what the user sees ~instantly. */
    private static function booted(int $cols, int $rows, ?Config $config = null): App
    {
        $app = self::sized(self::app($config), $cols, $rows);
        foreach (Cmds::run($app->init()) as $msg) {
            if ($msg instanceof TickRequest) {
                continue;
            }
            [$app] = $app->update($msg);
        }

        return $app;
    }
}
