<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Level;
use SugarCraft\Log\LogFilter;
use SugarCraft\Log\LogFormat;
use SugarCraft\Log\LogReader;
use SugarCraft\Log\LogRecord;
use SugarCraft\Log\LogTailMsg;
use SugarCraft\Log\LogTickMsg;
use SugarCraft\Log\LogViewer;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

final class LogViewerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sugar-log-viewer-' . \getmypid() . '-' . \spl_object_id($this);
        \mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            \is_dir($path) ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($this->dir);
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $bytes);

        return $path;
    }

    private static function json(string $level, string $msg, array $extra = []): string
    {
        return \json_encode(['level' => $level, 'msg' => $msg] + $extra, \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Runs the init read and folds it in, as the Program would.
     *
     * @return array{0: LogViewer, 1: ?\Closure}
     */
    private static function started(LogViewer $viewer): array
    {
        $read = $viewer->init();
        self::assertInstanceOf(\Closure::class, $read);
        $msg = $read();
        self::assertInstanceOf(LogTailMsg::class, $msg);

        [$next, $cmd] = $viewer->update($msg);
        self::assertInstanceOf(LogViewer::class, $next);

        return [$next, $cmd];
    }

    /** The Msg a tick Cmd produces when its timer fires. */
    private static function fire(\Closure $tick): LogTickMsg
    {
        $request = $tick();
        self::assertInstanceOf(TickRequest::class, $request);
        $msg = ($request->produce)();
        self::assertInstanceOf(LogTickMsg::class, $msg);

        return $msg;
    }

    /** Fires $tick into a following $viewer and runs the read it asks for, as the Program would. */
    private static function chainRead(LogViewer $viewer, \Closure $tick): LogTailMsg
    {
        [$same, $read] = $viewer->update(self::fire($tick));
        self::assertSame($viewer, $same, 'a tick only asks for a read');
        self::assertInstanceOf(\Closure::class, $read);
        $msg = $read();
        self::assertInstanceOf(LogTailMsg::class, $msg);
        self::assertTrue($msg->chained);

        return $msg;
    }

    /**
     * @param list<LogRecord> $records
     */
    private static function holding(LogViewer $viewer, array $records): LogViewer
    {
        [$next] = $viewer->update(new LogTailMsg($viewer->id, $viewer->generation, $records));
        self::assertInstanceOf(LogViewer::class, $next);

        return $next;
    }

    private static function f(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'f');
    }

    /**
     * @param list<LogRecord> $records
     * @return list<string>
     */
    private static function messages(array $records): array
    {
        return \array_map(static fn (LogRecord $r): string => $r->message, $records);
    }

    // ─── Model contract ─────────────────────────────────────────────

    public function testItIsASugarCoreModelWithNoSubscriptions(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'));

        self::assertInstanceOf(Model::class, $viewer);
        self::assertNull($viewer->subscriptions());
    }

    public function testNewFollowsWithTheDefaults(): void
    {
        $reader = LogReader::new($this->dir . '/app.log', LogFormat::Json);
        $viewer = LogViewer::new($reader);

        self::assertSame($reader, $viewer->reader);
        self::assertEquals(LogFilter::new(), $viewer->filter);
        self::assertTrue($viewer->follow);
        self::assertSame([], $viewer->records);
        self::assertSame(LogViewer::DEFAULT_INTERVAL, $viewer->interval);
        self::assertSame(LogViewer::DEFAULT_TAIL_SIZE, $viewer->tailSize);
        self::assertSame(LogViewer::DEFAULT_HEIGHT, $viewer->height);
        self::assertNull($viewer->width);
        self::assertNull($viewer->error);
    }

    public function testTwoViewersGetDistinctIds(): void
    {
        $reader = LogReader::new($this->dir . '/app.log');

        self::assertNotSame(LogViewer::new($reader)->id, LogViewer::new($reader)->id);
    }

    public function testInitReadsTheTailThroughACmdAndUpdateTakesIt(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one') . self::json('WARN', 'two'));
        $viewer = LogViewer::new(LogReader::new($path, LogFormat::Json));

        [$next, $cmd] = self::started($viewer);

        self::assertSame([], $viewer->records, 'the original keeps what it had');
        self::assertSame(['one', 'two'], self::messages($next->records));
        self::assertInstanceOf(\Closure::class, $cmd, 'a following viewer schedules its next read');
        self::assertInstanceOf(TickRequest::class, $cmd());
    }

    public function testInitStillReadsWhenNotFollowingAndTheChainIdlesWithoutReading(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        $viewer = LogViewer::new(LogReader::new($path, LogFormat::Json))->withFollow(false);

        [$next, $tick] = self::started($viewer);
        self::assertSame(['one'], self::messages($next->records));
        self::assertInstanceOf(\Closure::class, $tick);

        [$same, $cmd] = $next->update(self::fire($tick));
        self::assertSame($next, $same);
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(TickRequest::class, $cmd(), 'a paused viewer only waits for the next tick');
    }

    // ─── Follow ─────────────────────────────────────────────────────

    public function testAFollowTickAppendsTheLinesWrittenSinceTheLastRead(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one') . self::json('INFO', 'two'));
        $viewer = LogViewer::new(LogReader::new($path, LogFormat::Json))->withHeight(3)->withColor(false);
        [$viewer, $tick] = self::started($viewer);
        self::assertInstanceOf(\Closure::class, $tick);

        \file_put_contents($path, self::json('ERROR', 'three'), \FILE_APPEND);
        [$after, $nextTick] = $viewer->update(self::chainRead($viewer, $tick));

        self::assertSame(['one', 'two', 'three'], self::messages($after->records));
        self::assertSame("[INF] one\n[INF] two\n[ERR] three", $after->view());
        self::assertInstanceOf(\Closure::class, $nextTick, 'the chain continues while following');
        self::assertSame(['one', 'two'], self::messages($viewer->records), 'the pre-tick model is untouched');
    }

    public function testTheTickWaitsTheIntervalAndReadsWhenItFiresNotWhenBuilt(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        $viewer = LogViewer::new(LogReader::new($path, LogFormat::Json))->withInterval(0.25);
        $tick = $viewer->tick();

        $request = $tick();
        self::assertInstanceOf(TickRequest::class, $request);
        self::assertSame(0.25, $request->seconds);

        \file_put_contents($path, self::json('INFO', 'two'), \FILE_APPEND);
        $tickMsg = ($request->produce)();
        self::assertInstanceOf(LogTickMsg::class, $tickMsg);
        self::assertSame($viewer->id, $tickMsg->viewerId);

        $msg = self::chainRead($viewer, $tick);
        self::assertSame(['one', 'two'], self::messages($msg->records));
    }

    public function testRepeatedIdenticalLinesAreAllKept(): void
    {
        $line = self::json('INFO', 'same');
        $path = $this->file('app.log', $line . $line);
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json)));

        \file_put_contents($path, $line, \FILE_APPEND);
        [$after] = $viewer->update(self::chainRead($viewer, $tick));

        self::assertSame(['same', 'same', 'same'], self::messages($after->records));
    }

    public function testEachReadTakesAtMostTheTailSize(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'a') . self::json('INFO', 'b') . self::json('INFO', 'c'));
        [$viewer] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json))->withTailSize(2));

        self::assertSame(['b', 'c'], self::messages($viewer->records));
    }

    public function testFKeyPausesFollowAndDropsTheReadAlreadyInFlight(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json)));
        \file_put_contents($path, self::json('INFO', 'two'), \FILE_APPEND);
        $inFlight = self::chainRead($viewer, $tick);

        [$paused, $cmd] = $viewer->update(self::f());
        self::assertFalse($paused->follow);
        self::assertTrue($viewer->follow, 'toggling is an immutable change');
        self::assertNull($cmd);

        [$still, $next] = $paused->update($inFlight);
        self::assertSame($paused, $still, 'a paused viewer takes no late read');
        self::assertSame(['one'], self::messages($paused->records));
        self::assertInstanceOf(\Closure::class, $next, 'the chain outlives the dropped read');

        [$idle, $wait] = $paused->update(self::fire($next));
        self::assertSame($paused, $idle);
        self::assertInstanceOf(\Closure::class, $wait);
        self::assertInstanceOf(TickRequest::class, $wait(), 'a paused tick reads nothing and only waits');
    }

    public function testFKeyResumesWithAnImmediateReadAndOneTickChain(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        [$viewer, $oldTick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json)));
        [$paused] = $viewer->update(self::f());
        \file_put_contents($path, self::json('INFO', 'two'), \FILE_APPEND);

        [$resumed, $read] = $paused->update(self::f());
        self::assertTrue($resumed->follow);
        self::assertInstanceOf(\Closure::class, $read);
        $msg = $read();
        self::assertInstanceOf(LogTailMsg::class, $msg, 'resume reads at once, not after an interval');

        [$caughtUp, $none] = $resumed->update($msg);
        self::assertSame(['one', 'two'], self::messages($caughtUp->records));
        self::assertNull($none, 'the catch-up read starts no second chain');

        \file_put_contents($path, self::json('INFO', 'three'), \FILE_APPEND);
        [$after, $tick] = $caughtUp->update(self::chainRead($caughtUp, $oldTick));
        self::assertSame(['one', 'two', 'three'], self::messages($after->records), 'the one chain reads again');
        self::assertInstanceOf(\Closure::class, $tick);
    }

    public function testRefreshWhileFollowingNeverForksASecondChain(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json)));
        self::assertInstanceOf(\Closure::class, $tick);

        \file_put_contents($path, self::json('INFO', 'two'), \FILE_APPEND);
        $msg = $viewer->refresh()();
        self::assertInstanceOf(LogTailMsg::class, $msg);
        self::assertFalse($msg->chained);

        [$fresh, $cmd] = $viewer->update($msg);
        self::assertSame(['one', 'two'], self::messages($fresh->records));
        self::assertNull($cmd, 'the pending tick stays the only chain');

        // Run the one chain for a few rounds: each beat yields exactly one next beat.
        $pending = [$tick];
        for ($round = 0; $round < 3; $round++) {
            $next = [];
            foreach ($pending as $beat) {
                [$fresh, $cmd] = $fresh->update(self::chainRead($fresh, $beat));
                if ($cmd !== null) {
                    $next[] = $cmd;
                }
            }
            $pending = $next;
            self::assertCount(1, $pending, 'round ' . $round);
        }
    }

    public function testWithFollowTrueResumesAtTheNextTickWithNoCmd(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json))->withFollow(false));

        $resumed = $viewer->withFollow(true);
        \file_put_contents($path, self::json('INFO', 'two'), \FILE_APPEND);
        [$after] = $resumed->update(self::chainRead($resumed, $tick));

        self::assertSame(['one', 'two'], self::messages($after->records));
    }

    public function testModifiedFKeyAndOtherMessagesAreIgnored(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'));

        foreach ([new KeyMsg(KeyType::Char, 'f', ctrl: true), new KeyMsg(KeyType::Char, 'f', alt: true), new KeyMsg(KeyType::Char, 'g'), new KeyMsg(KeyType::Enter)] as $msg) {
            [$next, $cmd] = $viewer->update($msg);
            self::assertSame($viewer, $next);
            self::assertNull($cmd);
        }
    }

    public function testAnotherViewersReadIsIgnored(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'));
        $record = new LogRecord('x', Level::Info, 'x');

        foreach ([new LogTailMsg($viewer->id + 1000, $viewer->generation, [$record], null, true), new LogTickMsg($viewer->id + 1000)] as $msg) {
            [$next, $cmd] = $viewer->update($msg);
            self::assertSame($viewer, $next);
            self::assertNull($cmd);
        }
    }

    public function testWithFollowOnlyDropsReadsInFlightWhenItChangesTheValue(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'));

        $same = $viewer->withFollow(true);
        self::assertNotSame($viewer, $same);
        self::assertSame($viewer->generation, $same->generation, 'a no-op keeps the running chain alive');

        $paused = $viewer->withFollow(false);
        self::assertFalse($paused->follow);
        self::assertSame($viewer->generation + 1, $paused->generation);
    }

    public function testAFailedReadKeepsTheRecordsReportsTheErrorAndKeepsFollowing(): void
    {
        $path = $this->file('app.log', self::json('INFO', 'one'));
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json)));

        \unlink($path);
        \mkdir($path);
        [$failed, $retry] = $viewer->update(self::chainRead($viewer, $tick));

        self::assertSame(['one'], self::messages($failed->records));
        self::assertNotNull($failed->error);
        self::assertStringContainsString($path, $failed->error);
        self::assertInstanceOf(\Closure::class, $retry);

        \rmdir($path);
        \file_put_contents($path, self::json('INFO', 'back'));
        [$recovered] = $failed->update(self::chainRead($failed, $retry));
        self::assertNull($recovered->error);
        self::assertSame(['back'], self::messages($recovered->records));
    }

    public function testWithReaderDropsReadsOfTheOldFileAndTheChainReadsTheNewOne(): void
    {
        $old = $this->file('old.log', self::json('INFO', 'old'));
        $new = $this->file('new.log', self::json('INFO', 'new'));
        [$viewer, $tick] = self::started(LogViewer::new(LogReader::new($old, LogFormat::Json)));
        $inFlight = self::chainRead($viewer, $tick);

        $switched = $viewer->withReader(LogReader::new($new, LogFormat::Json));
        self::assertSame($viewer->generation + 1, $switched->generation);
        [$same, $next] = $switched->update($inFlight);
        self::assertSame($switched, $same, 'the old file\'s read is dropped');
        self::assertInstanceOf(\Closure::class, $next, 'the chain survives the swap with no Cmd from the caller');

        [$chained] = $switched->update(self::chainRead($switched, $next));
        self::assertSame(['new'], self::messages($chained->records), 'the next tick reads the new file');

        $msg = $switched->refresh()();
        self::assertInstanceOf(LogTailMsg::class, $msg);
        [$fresh] = $switched->update($msg);
        self::assertSame(['new'], self::messages($fresh->records));
    }

    // ─── Filter ─────────────────────────────────────────────────────

    public function testAFilterChangeReRendersTheHeldRecords(): void
    {
        $path = $this->file(
            'app.log',
            self::json('DEBUG', 'boot', ['svc' => 'api'])
            . self::json('INFO', 'ready', ['svc' => 'web'])
            . self::json('WARN', 'slow', ['svc' => 'api'])
            . self::json('ERROR', 'down', ['svc' => 'api']),
        );
        [$viewer] = self::started(LogViewer::new(LogReader::new($path, LogFormat::Json))->withColor(false)->withHeight(4));

        self::assertSame(
            "[DBG] boot svc=api\n[INF] ready svc=web\n[WRN] slow svc=api\n[ERR] down svc=api",
            $viewer->view(),
        );

        \unlink($path); // a filter change must not need the file
        $warn = $viewer->withFilter(LogFilter::new()->withMinLevel(Level::Warn));
        self::assertSame("[WRN] slow svc=api\n[ERR] down svc=api\n\n", $warn->view());
        self::assertSame(['slow', 'down'], self::messages($warn->visibleRecords()));

        $web = $viewer->withFilter(LogFilter::new()->withField('svc', 'web'));
        self::assertSame("[INF] ready svc=web\n\n\n", $web->view());

        self::assertCount(4, $viewer->visibleRecords(), 'the unfiltered model is untouched');
    }

    public function testNewTakesAFilter(): void
    {
        $filter = LogFilter::new()->withMinLevel(Level::Error);
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'), $filter),
            [new LogRecord('{}', Level::Info, 'i'), new LogRecord('{}', Level::Error, 'e')],
        );

        self::assertSame($filter, $viewer->filter);
        self::assertSame(['e'], self::messages($viewer->visibleRecords()));
    }

    // ─── View ───────────────────────────────────────────────────────

    /** @return list<LogRecord> */
    private static function fixture(): array
    {
        return [
            new LogRecord('{"level":"DEBUG"}', Level::Debug, 'boot', '10:00:00'),
            new LogRecord('{"level":"INFO"}', Level::Info, 'listening', '10:00:01', null, null, ['port' => 8080]),
            new LogRecord('{"level":"WARN"}', Level::Warn, 'slow query', '10:00:02', null, null, ['ms' => 950, 'sql' => 'select 1']),
            new LogRecord('{"level":"ERROR"}', Level::Error, 'a message far too long for the row', '10:00:03'),
            new LogRecord('plain text line', null, 'plain text line'),
        ];
    }

    public function testViewSnapshotPlain(): void
    {
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'))
                ->withColor(false)->withWidth(36)->withHeight(6)->withShowTimestamps(true),
            self::fixture(),
        );

        self::assertSame(
            "[10:00:00] [DBG] boot               \n"
            . "[10:00:01] [INF] listening port=8080\n"
            . "[10:00:02] [WRN] slow query ms=950 …\n"
            . "[10:00:03] [ERR] a message far too …\n"
            . "plain text line                     \n"
            . '                                    ',
            $viewer->view(),
        );
    }

    public function testViewSnapshotColoredPinsTheBytes(): void
    {
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'))->withWidth(24)->withHeight(4),
            \array_slice(self::fixture(), 1),
        );

        self::assertSame(
            "\e[34m[INF] listening port=80…\e[0m\n"
            . "\e[33m[WRN] slow query ms=950…\e[0m\n"
            . "\e[1m\e[31m[ERR] a message far too…\e[0m\n"
            . 'plain text line         ',
            $viewer->view(),
        );
    }

    public function testViewIsPureAndRepeatable(): void
    {
        $viewer = self::holding(LogViewer::new(LogReader::new($this->dir . '/none.log')), self::fixture());
        $before = \get_object_vars($viewer);

        self::assertSame($viewer->view(), $viewer->view());
        self::assertSame($before, \get_object_vars($viewer));
    }

    public function testViewKeepsTheNewestRowsAtTheBottomOrTheOldestAtTheTop(): void
    {
        $records = [];
        foreach (['a', 'b', 'c', 'd'] as $m) {
            $records[] = new LogRecord($m, null, $m);
        }
        $viewer = self::holding(LogViewer::new(LogReader::new($this->dir . '/none.log'))->withHeight(2), $records);

        self::assertSame("c\nd", $viewer->view());
        self::assertSame("a\nb", $viewer->withScrollToBottom(false)->view());

        $underfilled = self::holding(LogViewer::new(LogReader::new($this->dir . '/none.log'))->withHeight(4), \array_slice($records, 0, 2));
        self::assertSame("a\nb\n\n", $underfilled->view(), 'fewer records than rows show from the top');
    }

    public function testAnEmptyViewerRendersBlankRowsOfTheWidth(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'))->withHeight(2)->withWidth(3);

        self::assertSame("   \n   ", $viewer->view());
    }

    public function testTextLinesShowAsWrittenWithEscapesAndControlsRemoved(): void
    {
        $line = "2026 \e[34mINF\e[0m hi\tthere\x07";
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'))->withColor(false)->withHeight(1),
            [new LogRecord($line, Level::Info, $line)],
        );

        self::assertSame('2026 INF hi there', $viewer->view());
    }

    public function testStructuredRowsCanDropTheLevelAndShowThePrefix(): void
    {
        $record = new LogRecord('{}', Level::Info, 'msg', '10:00', 'db', null, ['q' => 'a b', 'ok' => true, 'n' => null, 'e' => '', 'list' => [1, 2]]);
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'))->withColor(false)->withHeight(1)->withShowLevel(false),
            [$record],
        );

        self::assertSame('db msg q="a b" ok=true n=null e="" list=[1,2]', $viewer->view());
    }

    public function testWithStylesTakesTheLevelPalette(): void
    {
        $styles = new Styles();
        $styles->levels[Level::Info->value] = Style::new()->foreground(Color::ansi(2));
        $viewer = self::holding(
            LogViewer::new(LogReader::new($this->dir . '/none.log'))->withHeight(1)->withStyles($styles),
            [new LogRecord('{}', Level::Info, 'go')],
        );
        $styles->levels[Level::Info->value] = Style::new()->foreground(Color::ansi(1));

        self::assertSame("\e[32m[INF] go\e[0m", $viewer->view(), 'later edits to the Styles do not reach the viewer');
    }

    // ─── Immutability and validation ────────────────────────────────

    /** @return iterable<string, array{\Closure(LogViewer): LogViewer, string}> */
    public static function withers(): iterable
    {
        $reader = LogReader::new('/elsewhere.log');
        $filter = LogFilter::new()->withMinLevel(Level::Warn);

        yield 'withReader' => [static fn (LogViewer $v): LogViewer => $v->withReader($reader), 'reader'];
        yield 'withFilter' => [static fn (LogViewer $v): LogViewer => $v->withFilter($filter), 'filter'];
        yield 'withFollow' => [static fn (LogViewer $v): LogViewer => $v->withFollow(false), 'follow'];
        yield 'withInterval' => [static fn (LogViewer $v): LogViewer => $v->withInterval(2.5), 'interval'];
        yield 'withTailSize' => [static fn (LogViewer $v): LogViewer => $v->withTailSize(7), 'tailSize'];
        yield 'withHeight' => [static fn (LogViewer $v): LogViewer => $v->withHeight(3), 'height'];
        yield 'withWidth' => [static fn (LogViewer $v): LogViewer => $v->withWidth(50), 'width'];
        yield 'withShowTimestamps' => [static fn (LogViewer $v): LogViewer => $v->withShowTimestamps(true), 'showTimestamps'];
        yield 'withShowLevel' => [static fn (LogViewer $v): LogViewer => $v->withShowLevel(false), 'showLevel'];
        yield 'withScrollToBottom' => [static fn (LogViewer $v): LogViewer => $v->withScrollToBottom(false), 'scrollToBottom'];
        yield 'withColor' => [static fn (LogViewer $v): LogViewer => $v->withColor(false), 'color'];
        yield 'withStyles' => [static function (LogViewer $v): LogViewer {
            $styles = new Styles();
            $styles->levels[Level::Info->value] = Style::new()->bold();

            return $v->withStyles($styles);
        }, 'levelStyles'];
    }

    /**
     * @param \Closure(LogViewer): LogViewer $with
     */
    #[DataProvider('withers')]
    public function testEveryWitherReturnsANewViewerAndLeavesTheOriginalAlone(\Closure $with, string $property): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'));
        $before = \get_object_vars($viewer);

        $next = $with($viewer);

        self::assertNotSame($viewer, $next);
        self::assertSame($before, \get_object_vars($viewer), 'the original is unchanged');
        self::assertNotEquals($before[$property], $next->{$property}, "{$property} changed on the copy");
        self::assertSame($viewer->id, $next->id, 'a copy routes the same reads');
    }

    public function testWithWidthNullGoesBackToUnpaddedRows(): void
    {
        $viewer = LogViewer::new(LogReader::new($this->dir . '/none.log'))->withWidth(10)->withWidth(null);

        self::assertNull($viewer->width);
    }

    /** @return iterable<string, array{\Closure(LogViewer): mixed}> */
    public static function invalidSettings(): iterable
    {
        yield 'zero interval' => [static fn (LogViewer $v): mixed => $v->withInterval(0.0)];
        yield 'negative interval' => [static fn (LogViewer $v): mixed => $v->withInterval(-1.0)];
        yield 'NaN interval' => [static fn (LogViewer $v): mixed => $v->withInterval(\NAN)];
        yield 'zero tail size' => [static fn (LogViewer $v): mixed => $v->withTailSize(0)];
        yield 'zero height' => [static fn (LogViewer $v): mixed => $v->withHeight(0)];
        yield 'zero width' => [static fn (LogViewer $v): mixed => $v->withWidth(0)];
    }

    /**
     * @param \Closure(LogViewer): mixed $set
     */
    #[DataProvider('invalidSettings')]
    public function testNonPositiveSettingsAreRejected(\Closure $set): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be greater than zero');

        $set(LogViewer::new(LogReader::new($this->dir . '/none.log')));
    }
}
