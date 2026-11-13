<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Width;
use SugarCraft\MdView\FileWatcher;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\ReloadTickMsg;

/**
 * Pager file-watch auto-reload. Everything is driven by dispatching
 * {@see ReloadTickMsg} straight into update(): no wall-clock, no real
 * timers, no sleep. Change detection is exercised through the real
 * (mtime, size) tuple on temp files — every edit changes the SIZE, so
 * same-second writes are caught without the second-granularity sleep the
 * old hasChangedSince test pays; one dedicated pin covers the mtime-only
 * edge. Geometry (WindowSizeMsg) and the document status header ride the
 * same message-driven discipline.
 *
 * @covers \SugarCraft\MdView\MdViewModel
 * @covers \SugarCraft\MdView\ReloadTickMsg
 */
final class MdViewModelReloadTest extends TestCase
{
    private ?string $watchedPath = null;

    protected function tearDown(): void
    {
        if ($this->watchedPath !== null && is_file($this->watchedPath)) {
            unlink($this->watchedPath);
        }
        $this->watchedPath = null;

        parent::tearDown();
    }

    private function createSourceFile(string $contents = 'v1'): string
    {
        $this->watchedPath = tempnam(sys_get_temp_dir(), 'mdview-t4-reload-');
        $this->assertIsString($this->watchedPath);
        file_put_contents($this->watchedPath, $contents);

        return $this->watchedPath;
    }

    /** Identity re-render stand-in: the closure IS the render boundary here. */
    private function markerReRender(): \Closure
    {
        return static fn(string $markdown): string => 'RENDERED<' . $markdown . '>';
    }

    private function armedModel(string $contents = 'v1'): MdViewModel
    {
        $path = $this->createSourceFile($contents);

        return MdViewModel::fromContent('RENDERED<' . $contents . '>', 40, 5)
            ->withWatch($path, $this->markerReRender());
    }

    public function testUnarmedModelHasNullWatchSurface(): void
    {
        $m = MdViewModel::fromContent('plain', 40, 5);

        self::assertFalse($m->isWatching());
        self::assertNull($m->init());
        self::assertNull($m->watchPath);
        self::assertSame(0, $m->reloadCount);
    }

    public function testArmedModelInitReturnsTheTickCommand(): void
    {
        $m = $this->armedModel();

        self::assertTrue($m->isWatching());
        self::assertIsString($m->watchPath);
        $cmd = $m->init();
        self::assertNotNull($cmd, 'armed pager must arm its sweep pump at start');

        $request = $cmd();
        self::assertInstanceOf(TickRequest::class, $request);
        self::assertSame(MdViewModel::RELOAD_POLL_SECONDS, $request->seconds);
        $msg = ($request->produce)();
        self::assertInstanceOf(ReloadTickMsg::class, $msg);
        self::assertSame(ReloadTickMsg::ID, $msg->id);
    }

    public function testIdleTickKeepsIdentityAndReArmsTheChain(): void
    {
        $m = $this->armedModel();

        [$next, $cmd] = $m->update(new ReloadTickMsg());

        self::assertSame($m, $next, 'unchanged file must return the identical instance');
        self::assertNotNull($cmd, 'every handled tick re-arms exactly one successor tick');
        self::assertSame(0, $next->reloadCount);
    }

    public function testSizeChangeReloadsContentInPlace(): void
    {
        $path = $this->createSourceFile('short');
        $m = MdViewModel::fromContent('RENDERED<short>', 40, 5)->withWatch($path, $this->markerReRender());

        file_put_contents($path, 'much longer body');

        // Tick 1 DETECTS and consumes the tuple; the read+re-render rides the
        // FOLLOWING tick (finding 017), so the detect pass itself stays a
        // single stat — keys never queue behind a parse.
        [$detect, $cmd] = $m->update(new ReloadTickMsg());
        self::assertSame(0, $detect->reloadCount, 'detection alone must not render');
        self::assertTrue($detect->pendingReload, 'a changed tuple must queue the deferred apply');
        self::assertStringNotContainsString('RENDERED<much longer body>', $detect->view());
        self::assertNotNull($cmd);

        [$next, $cmd] = $detect->update(new ReloadTickMsg());

        self::assertStringContainsString('RENDERED<much longer body>', $next->view());
        self::assertStringNotContainsString('RENDERED<short>', $next->view());
        self::assertSame(1, $next->reloadCount);
        self::assertFalse($next->pendingReload);
        self::assertNotNull($cmd);

        // Second edit, second two-tick cadence: the chain survives reloads.
        file_put_contents($path, 'final body text!!');
        [$detect2, ] = $next->update(new ReloadTickMsg());
        [$next2, $cmd2] = $detect2->update(new ReloadTickMsg());
        self::assertStringContainsString('RENDERED<final body text!!>', $next2->view());
        self::assertSame(2, $next2->reloadCount);
        self::assertNotNull($cmd2);
        // …and the re-armed instance still watches the same path.
        self::assertTrue($next2->isWatching());
    }

    public function testMtimeOnlyChangeIsDetectedByTheTuple(): void
    {
        $path = $this->createSourceFile('exactly this');
        $m = MdViewModel::fromContent('RENDERED<exactly this>', 40, 5)->withWatch($path, $this->markerReRender());

        // Same size, moved mtime (e.g. a `touch`, or a same-length in-place
        // edit inside one second) — the tuple law must still fire.
        touch($path, (int) filemtime($path) + 7);

        [$detect, ] = $m->update(new ReloadTickMsg());
        self::assertTrue($detect->pendingReload, 'an mtime-only move is a change under the tuple law');

        [$next, ] = $detect->update(new ReloadTickMsg());

        self::assertSame(1, $next->reloadCount);
    }

    public function testDeletedFileKeepsLastGoodFrameUntilRecreated(): void
    {
        $path = $this->createSourceFile('original body');
        $m = MdViewModel::fromContent('RENDERED<original body>', 40, 5)->withWatch($path, $this->markerReRender());

        unlink($path);
        [$afterDelete, ] = $m->update(new ReloadTickMsg());

        self::assertSame($m, $afterDelete, 'a missing file must blank nothing');
        self::assertSame(0, $afterDelete->reloadCount);

        // Recreate with different content: the baseline never advanced while
        // the file was gone, so the reappearance is detected as a change and
        // applied one tick later.
        file_put_contents($path, 'body is back bigger');
        [$detect, ] = $afterDelete->update(new ReloadTickMsg());
        [$afterRecreate, ] = $detect->update(new ReloadTickMsg());

        self::assertStringContainsString('RENDERED<body is back bigger>', $afterRecreate->view());
        self::assertSame(1, $afterRecreate->reloadCount);
    }

    public function testAbsentAtArmTimeReloadsOnFirstAppearance(): void
    {
        $ghost = sys_get_temp_dir() . '/glow-t4-appears-' . uniqid('', true) . '.md';
        $m = MdViewModel::fromContent('stale', 40, 5)->withWatch($ghost, $this->markerReRender());
        $this->watchedPath = $ghost;

        [$idle, ] = $m->update(new ReloadTickMsg());
        self::assertSame($m, $idle, 'still-absent file must not fire a reload');

        file_put_contents($ghost, 'first save');
        [$detect, ] = $idle->update(new ReloadTickMsg());
        [$appeared, ] = $detect->update(new ReloadTickMsg());

        self::assertStringContainsString('RENDERED<first save>', $appeared->view());
        self::assertSame(1, $appeared->reloadCount);
    }

    public function testKeyHandlingThreadsWatchStateForward(): void
    {
        $body = implode("\n", array_map(static fn(int $i): string => "row $i", range(1, 10)));
        $path = $this->createSourceFile($body);
        $m = MdViewModel::fromContent('RENDERED<' . $body . '>', 40, 3)->withWatch($path, $this->markerReRender());

        [$scrolled, ] = $m->update(new KeyMsg(KeyType::Down));

        self::assertSame(1, $scrolled->viewport->yOffset);
        self::assertTrue($scrolled->isWatching(), 'viewport pass-through must not drop the watch');

        // A reload after scrolling still applies (over both ticks).
        file_put_contents($path, 'changed contents');
        [$detect, ] = $scrolled->update(new ReloadTickMsg());
        [$reloaded, ] = $detect->update(new ReloadTickMsg());
        self::assertSame(1, $reloaded->reloadCount);
        self::assertStringContainsString('RENDERED<changed contents>', $reloaded->view());
    }

    public function testQuitDropsTheWatchAndKillsTheChain(): void
    {
        $m = $this->armedModel();
        [$exited, $quitCmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertTrue($exited->isExited());
        self::assertNotNull($quitCmd);

        [$after, $cmd] = $exited->update(new ReloadTickMsg());
        self::assertSame($after, $exited);
        self::assertNull($cmd, 'exited pager must never re-arm a sweep tick');
    }

    public function testForeignTickIdIsNotAReloadSignal(): void
    {
        $m = $this->armedModel('alpha');
        file_put_contents((string) $this->watchedPath, 'beta gamma');

        [$after, ] = $m->update(new ReloadTickMsg('someone-elses-chain'));

        self::assertSame(0, $after->reloadCount, 'id routing: foreign ticks must not reload');
        // It still rides the generic viewport path — watch state survives.
        self::assertTrue($after->isWatching());
    }

    public function testReloadPollConstantIsIdleBound(): void
    {
        // A POLL cadence (idle bound), never a request timeout, and a
        // documented human-scale value.
        self::assertGreaterThan(0.05, MdViewModel::RELOAD_POLL_SECONDS);
        self::assertLessThan(5.0, MdViewModel::RELOAD_POLL_SECONDS);
    }

    public function testWindowSizeMsgRebuildsTheViewportGeometry(): void
    {
        $body = "a\nb\n" . str_repeat("x\n", 30);
        $m = MdViewModel::fromContent($body, 40, 5);

        [$grown, $cmd] = $m->update(new WindowSizeMsg(20, 10));

        self::assertSame(20, $grown->viewport->width, 'terminal resize must reflow the viewport');
        self::assertSame(10, $grown->viewport->height);
        self::assertNull($cmd, 'a resize alone arms no command');
    }

    public function testStartupDuplicateWindowSizeMsgIsAnIdentityNoOp(): void
    {
        // Program dispatches the initial size once, and SIGWINCH may repeat
        // it verbatim: an unchanged (cols, rows) must not churn a new model.
        $m = MdViewModel::fromContent('body', 20, 10);

        [$same, ] = $m->update(new WindowSizeMsg(20, 10));

        self::assertSame($m, $same);
    }

    public function testDocumentModeAddsAHeaderRowAndReservesItsLine(): void
    {
        $m = MdViewModel::fromContent("body\nlines", 30, 5)->withDocument('README.md');

        self::assertSame(4, $m->viewport->height, 'the header costs the viewport one row');

        $lines = \explode("\n", $m->view());
        self::assertStringContainsString('README.md', $lines[0]);
        self::assertStringContainsString('%', $lines[0]);
        self::assertSame(30, Width::of($lines[0]), 'the header is padded to the full width');
    }

    public function testDocumentResizeKeepsTheHeaderRowAccounted(): void
    {
        $m = MdViewModel::fromContent("body\nlines", 30, 5)->withDocument('README.md');

        [$grown, ] = $m->update(new WindowSizeMsg(24, 8));

        self::assertSame(24, $grown->viewport->width);
        self::assertSame(7, $grown->viewport->height, 'the reserved header row survives resizes');
    }

    public function testOneRowTerminalPaintsTheHeaderAlone(): void
    {
        // Finding 038: rows=1 plus a document name used to emit TWO frame
        // lines into a 1-row alt screen — the overflow row wrapped and tore
        // every later repaint (height leg of the same corruption class as
        // finding 001; reachable via `resize -s 1 80` in a tmux pane).
        $m = MdViewModel::fromContent(str_repeat("x\n", 30), 40, 1)->withDocument('tiny.md');

        $lines = \explode("\n", $m->view());

        self::assertCount(1, $lines, 'the frame must never exceed the terminal height');
        self::assertStringContainsString('tiny.md', $lines[0]);
        self::assertSame(0, $m->viewport->height, 'the header reserves the only row');
    }

    public function testResizingToOneRowShedsTheBodyRowAndBackGrowsIt(): void
    {
        $m = MdViewModel::fromContent(str_repeat("x\n", 30), 40, 10)->withDocument('doc.md');

        [$one, ] = $m->update(new WindowSizeMsg(40, 1));
        self::assertCount(1, \explode("\n", $one->view()), 'rows=1 paints the header only');

        [$two, ] = $m->update(new WindowSizeMsg(40, 2));
        self::assertCount(2, \explode("\n", $two->view()), 'rows=2 = header plus one body line');

        // Chrome-free documents keep their single body row at rows=1.
        $plain = MdViewModel::fromContent('body', 40, 10);
        [$plainOne, ] = $plain->update(new WindowSizeMsg(40, 1));
        self::assertSame(['body'], \explode("\n", $plainOne->view()));
    }

    public function testReloadMarkerAppearsInHeaderAfterReloads(): void
    {
        $path = $this->createSourceFile('v1');
        $m = MdViewModel::fromContent('RENDERED<v1>', 40, 5)
            ->withDocument('notes.md')
            ->withWatch($path, $this->markerReRender());

        file_put_contents($path, 'v2 edited');
        [$detect, ] = $m->update(new ReloadTickMsg());
        [$reloaded, ] = $detect->update(new ReloadTickMsg());

        $header = \explode("\n", $reloaded->view())[0];
        self::assertStringContainsString('↻1', $header, 'silent swaps must become visible in the header');
        self::assertStringContainsString('notes.md', $header);
    }

    public function testAltQDoesNotQuitThePager(): void
    {
        $m = $this->armedModel();

        [$after, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q', alt: true));

        self::assertFalse($after->isExited(), 'Alt+q belongs to the terminal, not the pager');
        self::assertNull($cmd);
    }

    public function testCtrlQDoesNotQuitThePager(): void
    {
        $m = $this->armedModel();

        [$after, ] = $m->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));

        self::assertFalse($after->isExited());
    }

    public function testPlainQStillQuitsThePager(): void
    {
        // The unmodified rune keeps working — README documents q as the quit key.
        $m = $this->armedModel();

        [$after, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertTrue($after->isExited());
        self::assertNotNull($cmd);
    }

    public function testStateDerivationGoesThroughASingleCopySite(): void
    {
        // The 3-way hand-rolled ctor duplication silently zeroed watch state;
        // every derived instance must now flow through copy() from the one
        // genesis (fromContent) — the only other `new self(` is copy() itself.
        $source = \file_get_contents(\dirname(__DIR__) . '/src/MdViewModel.php');
        self::assertIsString($source);
        self::assertSame(
            2,
            \substr_count($source, 'new self('),
            'fromContent genesis + copy() body only; add state to the ctor, never a fourth copy',
        );
    }

    public function testFileWatcherTupleApiFeedsTheModelSweep(): void
    {
        $path = $this->createSourceFile('abc');

        $baseline = FileWatcher::snapshot($path);
        self::assertIsArray($baseline);
        self::assertNull(FileWatcher::pollTuple($path, $baseline[0], $baseline[1]));

        file_put_contents($path, 'abcd');
        $moved = FileWatcher::pollTuple($path, $baseline[0], $baseline[1]);
        self::assertIsArray($moved);
        self::assertSame(4, $moved[1]);

        self::assertNull(FileWatcher::snapshot($path . '.missing'));
        // The [0,0] sentinel baseline: an appearing file IS a change.
        $appeared = FileWatcher::pollTuple($path, 0, 0);
        self::assertIsArray($appeared);
    }

    public function testFileWatcherIsSingleShotApiWithoutAnyPump(): void
    {
        // Finding 080's delete leg, pinned against re-introduction: the
        // pager has ONE watcher (the model's fixed tick) and FileWatcher
        // carries no generator, no ladder and no sleeping of its own.
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/FileWatcher.php');
        self::assertStringNotContainsString('usleep(', $source);
        self::assertStringNotContainsString('function watch(', $source);
        self::assertStringNotContainsString('idlePollDelay', $source);
    }

    public function testSaveBurstCoalescesToASingleDeferredRender(): void
    {
        // Finding 017's coalesce/drop-if-stale leg: the read+render rides a
        // follow-up tick, so every edit that lands between two ticks is
        // paid for exactly once — with the NEWEST bytes.
        $path    = $this->createSourceFile('one');
        $renders = 0;
        $counter = static function (string $raw) use (&$renders): string {
            $renders++;

            return 'RENDERED<' . $raw . '>';
        };
        $m = MdViewModel::fromContent('RENDERED<one>', 40, 5)->withWatch($path, $counter);

        file_put_contents($path, 'edit-A');
        [$detect, ] = $m->update(new ReloadTickMsg());
        self::assertSame(0, $renders, 'the detect pass must be a bare stat — no read, no render');

        file_put_contents($path, 'edit-BB');
        file_put_contents($path, 'edit-CCC');
        [$applied, ] = $detect->update(new ReloadTickMsg());

        self::assertSame(1, $renders, 'a burst between ticks renders exactly once');
        self::assertSame(1, $applied->reloadCount);
        self::assertStringContainsString('RENDERED<edit-CCC>', $applied->view(), 'last save wins');

        [$held, ] = $applied->update(new ReloadTickMsg());
        self::assertSame($applied, $held, 'the consumed burst must not re-render on a later tick');
        self::assertSame(1, $renders);
    }

    public function testThrowingReRenderKeepsLastGoodFrameAndRecoversOnNextSave(): void
    {
        // Finding 019: sugar-markdown can throw on hostile-but-readable
        // input; the blowup used to escape update() and kill the pager mid
        // save. Contained now: last good frame + ↻!. The tuple is consumed
        // either way — retrying the SAME bytes would re-run the same
        // deterministic throw every half second (documented divergence from
        // the "baseline not consumed" wording).
        $path = $this->createSourceFile('calm');
        $hostile = static function (string $raw): string {
            if (str_contains($raw, 'boom')) {
                throw new \RuntimeException('hostile nesting blew the renderer');
            }

            return 'RENDERED<' . $raw . '>';
        };
        $m = MdViewModel::fromContent('RENDERED<calm>', 40, 5)
            ->withDocument('x.md')
            ->withWatch($path, $hostile);

        file_put_contents($path, 'boom boom'); // size differs: same-size same-second saves are invisible by (mtime, size) law
        [$detect, ] = $m->update(new ReloadTickMsg());
        [$blocked, ] = $detect->update(new ReloadTickMsg());

        self::assertStringContainsString('RENDERED<calm>', $blocked->view(), 'a throwing render must not blank the pager');
        self::assertStringContainsString('↻!', $blocked->view());
        self::assertSame(0, $blocked->reloadCount);

        [$held, ] = $blocked->update(new ReloadTickMsg());
        self::assertSame($blocked, $held, 'identical bytes are not re-paid every tick');

        file_put_contents($path, 'fixed text');
        [$redetect, ] = $held->update(new ReloadTickMsg());
        [$fixed, ] = $redetect->update(new ReloadTickMsg());

        self::assertSame(1, $fixed->reloadCount);
        self::assertStringContainsString('RENDERED<fixed text>', $fixed->view());
        self::assertFalse($fixed->reloadBlocked);
    }

    public function testForceKeyReloadsTupleInvisibleRewrite(): void
    {
        // Finding 044: a rewrite preserving BOTH second-granularity mtime
        // and byte size is invisible to the tuple forever. `r` (unmodified,
        // watching) forces the deferred apply over the current bytes.
        $path = $this->createSourceFile('AAAA');
        $m = MdViewModel::fromContent('RENDERED<AAAA>', 40, 5)->withWatch($path, $this->markerReRender());
        $base = (int) filemtime($path);

        file_put_contents($path, 'BBBB');
        touch($path, $base); // same size, mtime rewound: same fingerprint

        [$idle, ] = $m->update(new ReloadTickMsg());
        self::assertSame($idle, $m, 'a same-fingerprint rewrite stays invisible to the sweep — documented, and r exists for it');

        [$armed, $cmd] = $idle->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertNull($cmd, 'r queues onto the live tick chain instead of double-arming it');
        self::assertTrue($armed->pendingReload);

        [$applied, ] = $armed->update(new ReloadTickMsg());
        self::assertSame(1, $applied->reloadCount);
        self::assertStringContainsString('RENDERED<BBBB>', $applied->view());

        // Modified r is not the force key — those chords belong to the
        // viewport pass-through, same discipline as the q quit gate.
        file_put_contents($path, 'CCCC');
        touch($path, $base);
        [$mod, ] = $applied->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        self::assertFalse($mod->pendingReload);
        self::assertSame(1, $mod->reloadCount);
    }

    /**
     * Finding 007's lifecycle leg: the front-matter rule must hold on a
     * reload too, or an autosaved Jekyll document would flash its fence as
     * a setext heading the moment the watcher fires.
     */
    public function testReloadAppliesTheSameFrontMatterStrip(): void
    {
        $path = $this->createSourceFile('short');
        $m = MdViewModel::fromContent('RENDERED<short>', 40, 5)->withWatch($path, $this->markerReRender());

        file_put_contents($path, "---\ntitle: My Doc\ntags: [a, b]\n---\nBODY\n");

        [$detect, ] = $m->update(new ReloadTickMsg());
        [$next, ]   = $detect->update(new ReloadTickMsg());

        self::assertStringContainsString('RENDERED<BODY', $next->view());
        self::assertStringNotContainsString('title: My Doc', $next->view(), 'reload must strip through the same rule');
        self::assertSame(1, $next->reloadCount);
    }
}
