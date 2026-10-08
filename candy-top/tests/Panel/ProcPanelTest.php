<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\Tests\Support\ProcRows;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;

/**
 * Behaviour of the proc box: every key, the filter prompt, tree keys, the
 * detailed view and the mouse. A local driver applies PanelResult::$set
 * to its Config the way App::deliver does.
 */
final class ProcPanelTest extends TestCase
{
    private Config $config;
    private Layout $layout;
    private Rect $box;
    private ?PanelResult $last = null;

    protected function setUp(): void
    {
        $this->resize(120, 40);
    }

    private function resize(int $cols, int $rows): void
    {
        $this->config ??= Config::new();
        $this->layout = FrameBuilder::layout($cols, $rows, $this->config, 8);
        $box = $this->layout->box('proc');
        $this->assertNotNull($box);
        $this->box = $box;
    }

    private function ctx(bool $visible = true): PanelContext
    {
        return new PanelContext($this->config, $this->layout, $visible ? $this->box : null);
    }

    private function send(ProcPanel $p, Msg $msg, bool $visible = true): ProcPanel
    {
        $this->last = $p->update($msg, $this->ctx($visible));
        foreach ($this->last->set as $k => $v) {
            $this->config = $this->config->with($k, $v);
        }
        $next = $this->last->panel;
        $this->assertInstanceOf(ProcPanel::class, $next);

        return $next;
    }

    private function sample(ProcPanel $p, int $times = 1): ProcPanel
    {
        for ($i = 0; $i < $times; $i++) {
            $cmd = $p->collect($this->ctx());
            $this->assertNotNull($cmd);
            $p = $this->send($p, $cmd());
        }

        return $p;
    }

    private static function key(string $k): KeyMsg
    {
        return match ($k) {
            'up' => new KeyMsg(KeyType::Up),
            'down' => new KeyMsg(KeyType::Down),
            'left' => new KeyMsg(KeyType::Left),
            'right' => new KeyMsg(KeyType::Right),
            'pgup' => new KeyMsg(KeyType::PageUp),
            'pgdn' => new KeyMsg(KeyType::PageDown),
            'home' => new KeyMsg(KeyType::Home),
            'end' => new KeyMsg(KeyType::End),
            'enter' => new KeyMsg(KeyType::Enter),
            'esc' => new KeyMsg(KeyType::Escape),
            'bs' => new KeyMsg(KeyType::Backspace),
            'del' => new KeyMsg(KeyType::Delete),
            'space' => new KeyMsg(KeyType::Space, ' '),
            default => new KeyMsg(KeyType::Char, $k),
        };
    }

    private function press(ProcPanel $p, string ...$keys): ProcPanel
    {
        foreach ($keys as $k) {
            $p = $this->send($p, self::key($k));
        }

        return $p;
    }

    /** A mouse event at box-local ($lx, $ly). */
    private function mouse(int $lx, int $ly, MouseButton $button = MouseButton::Left, MouseAction $action = MouseAction::Press): MouseMsg
    {
        return new MouseMsg($this->box->x + 1 + $lx, $this->box->y + 1 + $ly, $button, $action);
    }

    private function demo(int $extra = 0): ProcPanel
    {
        return $this->sample(ProcPanel::new(FakeProcList::demo(8, $extra)), 2);
    }

    /** @return list<int> */
    private function rowPids(ProcPanel $p): array
    {
        return ProcRows::pids($p->rows($this->config));
    }

    /**
     * A source replaying fixed snapshots, counting its samples.
     *
     * @param list<ProcSnapshot> $snaps
     */
    private static function replay(array $snaps, ?\ArrayObject $count = null): Source
    {
        return new class ($snaps, 0, $count ?? new \ArrayObject()) implements Source {
            /** @param list<ProcSnapshot> $snaps */
            public function __construct(private array $snaps, private int $i, private \ArrayObject $count)
            {
            }

            public function sample(): array
            {
                $this->count->append(1);

                return [$this->snaps[min($this->i, count($this->snaps) - 1)], new self($this->snaps, $this->i + 1, $this->count)];
            }
        };
    }

    // ---- sampling -------------------------------------------------------------

    public function testCollectSamplesInsideTheCmdOnly(): void
    {
        $count = new \ArrayObject();
        $panel = ProcPanel::new(self::replay([new ProcSnapshot([ProcRows::process(1)], 1, 1 << 30)], $count));
        $cmd = $panel->collect($this->ctx());
        $this->assertCount(0, $count, 'building the Cmd reads nothing');
        $msg = $cmd();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('proc', $msg->box);
        $this->assertCount(1, $count);
        $this->assertNull($panel->snapshot());
        $next = $this->send($panel, $msg);
        $this->assertSame($msg->snapshot, $next->snapshot());
        $this->assertSame($msg->next, $next->source());
        $this->assertSame('proc', $next->box());
        $this->assertSame($next, $this->send($next, new SampledMsg('cpu', $msg->snapshot, $msg->next)), 'foreign samples are ignored');
        $this->assertSame($next, $this->send($next, new FocusGainedMsg()));
    }

    public function testCollectTurnsIoOnOnlyForWideBoxesOrIoSorts(): void
    {
        $io = static fn (ProcPanel $p): bool => array_filter(
            $p->snapshot()?->processes ?? [],
            static fn ($proc): bool => $proc->ioRead >= 0.0,
        ) !== [];
        $this->resize(180, 50);
        $this->assertGreaterThanOrEqual(90, $this->box->width);
        $this->assertTrue($io($this->demo()), 'IO/R IO/W columns on screen');

        $this->resize(100, 30);
        $this->assertLessThan(90, $this->box->width);
        $this->assertFalse($io($this->demo()), 'no columns, no extra /proc/[pid]/io reads');
        $this->config = $this->config->with('proc_sorting', 'io total');
        $this->assertTrue($io($this->demo()), 'an io sort needs the rates');
    }

    public function testCollectAppliesPerCoreAndKernelFilter(): void
    {
        $plain = $this->demo()->snapshot();
        $this->config = $this->config->with('proc_per_core', true)->with('proc_filter_kernel', true);
        $tuned = $this->demo()->snapshot();
        $this->assertNotNull($plain);
        $this->assertNotNull($tuned);
        $this->assertEqualsWithDelta($plain->processes[0]->cpu * 8, $tuned->processes[0]->cpu, 0.1);
        $pids = array_map(static fn ($p): int => $p->pid, $tuned->processes);
        $this->assertNotContains(2, $pids);
    }

    public function testCollectKeepsTheLiveCollectorWrapped(): void
    {
        $panel = ProcPanel::new(CollectorSource::of(ProcList::new(\SugarCraft\Top\Tests\Collect\Support\FixtureTree::committed())));
        $msg = ($panel->collect($this->ctx()))();
        $this->assertInstanceOf(CollectorSource::class, $msg->next);
        $this->assertInstanceOf(ProcList::class, $msg->next->collector());
    }

    public function testUnmeasuredCpuKeepsTheLastGoodValueButIoDoesNot(): void
    {
        $first = new ProcSnapshot([ProcRows::process(1, ['cpu' => 12.5, 'ioR' => 300.0])], 1, 1 << 30);
        $second = new ProcSnapshot([ProcRows::process(1, ['cpu' => -1.0, 'ioR' => -1.0]), ProcRows::process(7, ['cpu' => -1.0])], 1, -1);
        $p = $this->sample(ProcPanel::new(self::replay([$first, $second])), 2);
        $rows = $p->rows($this->config);
        $byPid = [];
        foreach ($rows as $r) {
            $byPid[$r->pid()] = $r;
        }
        $this->assertSame(12.5, $byPid[1]->cpu, '#1008: hold the last value');
        $this->assertSame(-1.0, $byPid[1]->ioRead, 'io is never carried: gate off, EACCES or a first sample all read "-"');
        $this->assertSame(0.0, $byPid[7]->cpu, 'never measured reads 0');
        $this->assertSame(-1.0, $byPid[7]->ioRead, 'unknown io stays unknown ("-")');
    }

    public function testEntriesKeepThePreviousOrderSoTiesAreStable(): void
    {
        $this->config = $this->config->with('proc_sorting', 'memory');
        $a = new ProcSnapshot([ProcRows::process(1, ['mem' => 5]), ProcRows::process(2, ['mem' => 9]), ProcRows::process(3, ['mem' => 5])], 1, 100);
        $b = new ProcSnapshot([ProcRows::process(1, ['mem' => 5]), ProcRows::process(2, ['mem' => 5]), ProcRows::process(3, ['mem' => 5]), ProcRows::process(4, ['mem' => 5])], 1, 100);
        $p = $this->sample(ProcPanel::new(self::replay([$a, $b])));
        $this->assertSame([2, 1, 3], $this->rowPids($p));
        $p = $this->sample($p);
        $this->assertSame([2, 1, 3, 4], $this->rowPids($p), 'all tied: the last order wins, new pids at the end');
    }

    // ---- input routing ---------------------------------------------------------

    public function testModalOnlyWhileFilteringAndVisible(): void
    {
        $p = $this->demo();
        $this->assertFalse($p->modal($this->ctx()));
        $this->config = $this->config->with('proc_filtering', true);
        $this->assertTrue($p->modal($this->ctx()));
        $this->assertFalse($p->modal($this->ctx(false)), 'a hidden box never owns input');
    }

    public function testCapturesTreeKeysOnlyInTreeViewWithASelection(): void
    {
        $p = $this->demo();
        foreach (['+', '-', '=', 'C', 'space'] as $k) {
            $this->assertFalse($p->capturesKey(self::key($k), $this->ctx()), "$k: tree off");
        }
        $this->config = $this->config->with('proc_tree', true);
        $this->assertFalse($p->capturesKey(self::key('+'), $this->ctx()), 'nothing selected: + steps update_ms');
        $p = $this->press($p, 'down');
        foreach (['+', '-', '=', 'C', 'space'] as $k) {
            $this->assertTrue($p->capturesKey(self::key($k), $this->ctx()), $k);
        }
        foreach (['q', 'f', 'up', 'r'] as $k) {
            $this->assertFalse($p->capturesKey(self::key($k), $this->ctx()), $k);
        }
        $this->assertFalse($p->capturesKey(self::key('+'), $this->ctx(false)));
    }

    public function testHiddenBoxIgnoresInputButStillSamples(): void
    {
        $p = $this->demo();
        $this->assertSame($p, $this->send($p, self::key('down'), false));
        $this->assertSame($p, $this->send($p, $this->mouse(5, 5), false));
        $this->assertNotSame($p, $this->send($p, ($p->collect($this->ctx()))(), false));
    }

    // ---- option keys -----------------------------------------------------------

    public function testLeftRightCycleTheSortThroughBtopsVectorAndWrap(): void
    {
        $p = $this->demo();
        $p = $this->press($p, 'right');
        $this->assertSame(['proc_sorting' => 'io read'], $this->last?->set);
        $p = $this->press($p, 'right', 'right', 'right');
        $this->assertSame('pid', $this->config->procSorting(), 'wraps after io total');
        $p = $this->press($p, 'left');
        $this->assertSame('io total', $this->config->procSorting());
        $this->assertSame('pid', ProcPanel::cycleSort('bogus', 1), 'unknown: right goes to the first');
        $this->assertSame('io total', ProcPanel::cycleSort('bogus', -1), 'unknown: left goes to the last');
    }

    public function testToggleKeysWriteTheirOptions(): void
    {
        $p = $this->demo();
        foreach (['r' => 'proc_reversed', 'c' => 'proc_per_core', '%' => 'proc_mem_bytes', 'O' => 'proc_filter_containers', 'e' => 'proc_tree'] as $k => $opt) {
            $before = $this->config->bool($opt);
            $p = $this->press($p, $k);
            $this->assertSame([$opt => !$before], $this->last?->set, $k);
            $this->assertSame(!$before, $this->config->bool($opt));
        }
    }

    public function testReverseReordersTheRowsAtOnce(): void
    {
        $this->config = $this->config->with('proc_sorting', 'pid');
        $p = $this->demo();
        $this->assertSame(7001, $this->rowPids($p)[0]);
        $p = $this->press($p, 'r');
        $this->assertSame(1, $this->rowPids($p)[0]);
    }

    public function testOmitContainersHidesContainerAndVmProcesses(): void
    {
        $p = $this->demo();
        $this->assertContains(5133, $this->rowPids($p));
        $this->assertContains(6969, $this->rowPids($p));
        $p = $this->press($p, 'O');
        $this->assertNotContains(5133, $this->rowPids($p));
        $this->assertNotContains(6969, $this->rowPids($p), 'U1b: the filter covers VMs');
    }

    public function testDeleteClearsAFilterOnly(): void
    {
        $p = $this->demo();
        $same = $this->press($p, 'del');
        $this->assertSame([], $this->last?->set);
        $this->config = $this->config->with('proc_filter', 'nginx');
        $this->press($same, 'del');
        $this->assertSame(['proc_filter' => ''], $this->last?->set);
    }

    public function testSignalFollowAndPauseKeysAreLeftForLaterPhases(): void
    {
        $p = $this->press($this->demo(), 'down');
        foreach (['t', 'k', 's', 'N', 'F', 'u'] as $k) {
            $this->assertSame($p, $this->press($p, $k), $k);
            $this->assertSame([], $this->last?->set);
            $this->assertNull($this->last?->cmd);
        }
    }

    // ---- navigation ------------------------------------------------------------

    public function testArrowAndPageKeysMoveTheSelection(): void
    {
        $p = $this->demo(100);
        $max = $this->ctx()->procSelectMax();
        $p = $this->press($p, 'down', 'down');
        $this->assertSame([0, 2], [$p->selection()->start, $p->selection()->selected]);
        $p = $this->press($p, 'up');
        $this->assertSame(1, $p->selection()->selected);
        $p = $this->press($p, 'pgdn');
        $this->assertSame($max, $p->selection()->start);
        $p = $this->press($p, 'end');
        $n = count($p->rows($this->config));
        $this->assertSame($max, $p->selection()->selected, 'end with a selection puts the bar on the last row');
        $this->assertSame([$n - $max, $max], [$p->selection()->start, $p->selection()->selected]);
        $pids = $this->rowPids($p);
        $this->assertSame(end($pids), $p->selectedPid($this->config));
        $p = $this->press($p, 'pgup');
        $this->assertSame($n - 2 * $max, $p->selection()->start);
        $p = $this->press($p, 'home');
        $this->assertSame([0, 1], [$p->selection()->start, $p->selection()->selected]);
    }

    public function testVimKeysOnlyWithVimKeys(): void
    {
        $p = $this->demo(100);
        $this->assertSame(0, $this->press($p, 'j')->selection()->selected, 'j is not down without vim_keys');
        $this->config = $this->config->with('vim_keys', true);
        $p = $this->press($p, 'j', 'j', 'k');
        $this->assertSame(1, $p->selection()->selected);
        $p = $this->press($p, 'G');
        $this->assertGreaterThan(0, $p->selection()->start);
        $p = $this->press($p, 'g');
        $this->assertSame(0, $p->selection()->start);
        $this->press($p, 'l');
        $this->assertSame(['proc_sorting' => 'io read'], $this->last?->set);
        $this->press($p, 'h');
        $this->assertSame(['proc_sorting' => 'cpu lazy'], $this->last?->set, 'back from io read');
    }

    public function testResizeClampsTheSelection(): void
    {
        $p = $this->press($this->demo(100), 'down', 'end');
        $this->resize(100, 30);
        $p = $this->send($p, new WindowSizeMsg(100, 30));
        $max = $this->ctx()->procSelectMax();
        $this->assertSame($max, $p->selection()->selected, 'the bar is pulled back inside the shorter list');
        $this->assertLessThanOrEqual(count($p->rows($this->config)) - $max, $p->selection()->start);
    }

    // ---- filter ----------------------------------------------------------------

    public function testFilterPromptOwnsEveryKeyAndFiltersLive(): void
    {
        $p = $this->demo();
        $p = $this->press($p, 'f');
        $this->assertSame(['proc_filtering' => true], $this->last?->set);
        $this->assertTrue($p->modal($this->ctx()));
        $p = $this->press($p, 'n', 'g');
        $this->assertSame(['proc_filter' => 'ng'], $this->last?->set, 'live filtering');
        $this->assertSame([1203], $this->rowPids($p));
        $p = $this->press($p, 'q', '1');
        $this->assertSame('ngq1', $this->config->string('proc_filter'), 'q and digits are text while filtering');
        $p = $this->press($p, 'bs', 'bs');
        $this->assertSame('ng', $this->config->string('proc_filter'));
        $this->assertSame($p, $this->press($p, 'up'), 'a non-edit key is dropped');
        $p = $this->press($p, 'enter');
        $this->assertSame(['proc_filter' => 'ng', 'proc_filtering' => false], $this->last?->set);
        $this->assertNull($p->filterEdit());
        $this->assertSame([1203], $this->rowPids($p));
    }

    public function testSlashOpensTheFilterAndEscapeRestoresTheOldOne(): void
    {
        $this->config = $this->config->with('proc_filter', 'post');
        $p = $this->press($this->demo(), '/', 'bs', 'bs', 'bs', 'bs', 'x');
        $this->assertSame('x', $this->config->string('proc_filter'));
        $p = $this->press($p, 'esc');
        $this->assertSame(['proc_filter' => 'post', 'proc_filtering' => false], $this->last?->set);
        $this->assertSame([880], $this->rowPids($p));
    }

    public function testDownCommitsTheFilterAndMovesDown(): void
    {
        $p = $this->press($this->demo(), 'f', 'o');
        $p = $this->press($p, 'down');
        $this->assertFalse($this->config->bool('proc_filtering'));
        $this->assertSame('o', $this->config->string('proc_filter'));
        $this->assertSame(1, $p->selection()->selected);
    }

    public function testAClickWhileFilteringCancelsLikeEscape(): void
    {
        $p = $this->press($this->demo(), 'f', 'z');
        $this->send($p, $this->mouse(5, 5));
        $this->assertSame(['proc_filter' => '', 'proc_filtering' => false], $this->last?->set);
    }

    public function testRegexFilter(): void
    {
        $this->config = $this->config->with('proc_filter', '!^(nginx|postgres)$');
        $this->assertSame([880, 1203], array_values(array_intersect([880, 1203], $this->rowPids($this->demo()))));
        $this->assertCount(2, $this->rowPids($this->demo()));
    }

    // ---- tree ------------------------------------------------------------------

    private function treeAt(int $pid): ProcPanel
    {
        $this->config = $this->config->with('proc_sorting', 'pid')->with('proc_reversed', true);
        $p = $this->press($this->demo(), 'e');
        $this->assertTrue($this->config->bool('proc_tree'));
        while ($p->selectedPid($this->config) !== $pid) {
            $p = $this->press($p, 'down');
        }

        return $p;
    }

    public function testTreeKeysCollapseExpandAndKeepTheSelection(): void
    {
        $p = $this->treeAt(412); // sshd → bash → {php, vim}
        $full = count($this->rowPids($p));
        $p = $this->press($p, '-');
        $this->assertSame($full - 3, count($this->rowPids($p)));
        $this->assertSame(412, $p->selectedPid($this->config), 'locate_selection');
        $this->assertTrue($p->collapsed()[412]);
        $p = $this->press($p, '+');
        $this->assertSame($full, count($this->rowPids($p)));
        $p = $this->press($p, 'space');
        $this->assertSame($full - 3, count($this->rowPids($p)), 'space toggles');
        $p = $this->press($p, '=');
        $this->assertSame($full, count($this->rowPids($p)), '= expands like +');
        $p = $this->press($p, 'C');
        $this->assertTrue($p->collapsed()[2210], 'C toggles the children');
        $this->assertSame($full - 2, count($this->rowPids($p)));
        $p = $this->press($p, 'E');
        $this->assertLessThan($full - 2, count($this->rowPids($p)), 'E collapses every non-root parent');
    }

    public function testTreeKeysAreIgnoredOutsideTreeView(): void
    {
        $p = $this->press($this->demo(), 'down');
        $this->assertSame($p, $this->press($p, '-'));
        $this->assertSame($p, $this->press($p, 'E'));
    }

    public function testAutoCollapseWhenEnteringTreeView(): void
    {
        $this->config = $this->config->with('proc_tree_auto_collapse', 2);
        $p = $this->press($this->demo(), 'e');
        $this->assertTrue($p->collapsed()[2210] ?? false, 'bash has two children and sits below a root child');
    }

    // ---- detailed view -----------------------------------------------------------

    public function testEnterOpensTheDetailedViewAndSamplesAtOnce(): void
    {
        $p = $this->demo();
        $this->assertSame($p, $this->press($p, 'enter'), 'nothing selected, nothing shown: no-op');
        $p = $this->press($p, 'down', 'down');
        $pid = $p->selectedPid($this->config);
        $p = $this->press($p, 'enter');
        $this->assertSame(['show_detailed' => true], $this->last?->set);
        $this->assertSame($pid, $p->detail()?->pid);
        $this->assertSame([0, 2], [$p->selection()->selected, $p->selection()->lastSelected]);
        $cmd = $this->last?->cmd;
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertInstanceOf(ProcSnapshot::class, $msg->snapshot);
        $this->assertSame($pid, $msg->snapshot->detail?->pid, '#1546: the detail pid is read in the Cmd');
        $p = $this->send($p, $msg);
        $this->assertSame('/home/' . $p->detail()?->entry?->process->user, $p->detail()?->extra?->cwd);
        $this->assertCount(1, $p->detail()?->cpu ?? []);
    }

    public function testEnterClosesAndRestoresTheDetailedProcess(): void
    {
        $p = $this->press($this->demo(), 'down', 'down', 'down');
        $pid = $p->selectedPid($this->config);
        $p = $this->press($p, 'enter');
        $p = $this->press($p, 'enter');
        $this->assertSame(['show_detailed' => false], $this->last?->set);
        $this->assertNull($p->detail());
        $this->assertSame($pid, $p->selectedPid($this->config), 'proc_follow_detailed: back on the process');

        $this->config = $this->config->with('proc_follow_detailed', false);
        $p = $this->press($p, 'up', 'enter');
        $this->assertSame([0, 2], [$p->selection()->selected, $p->selection()->lastSelected]);
        $p = $this->press($p, 'enter');
        $this->assertSame([2, 0], [$p->selection()->selected, $p->selection()->lastSelected], 'without follow: the row it was opened from');
    }

    public function testEnterOnAnotherRowSwitchesTheDetailedProcess(): void
    {
        $p = $this->press($this->demo(), 'down', 'enter', 'down', 'down');
        $other = $p->selectedPid($this->config);
        $p = $this->press($p, 'enter');
        $this->assertSame($other, $p->detail()?->pid);
    }

    public function testSelectionUsesTheFullListWhenTheDetailDoesNotFit(): void
    {
        $narrow = Rect::new($this->box->x, $this->box->y, 28, $this->box->height);
        $this->assertFalse(ProcView::detailFits($narrow->width, $narrow->height));
        $ctx = new PanelContext($this->config, $this->layout, $narrow);
        $p = $this->demo(100);
        foreach ([new KeyMsg(KeyType::Down), new KeyMsg(KeyType::Enter)] as $k) {
            $r = $p->update($k, $ctx);
            foreach ($r->set as $opt => $v) {
                $this->config = $this->config->with($opt, $v);
            }
            $ctx = new PanelContext($this->config, $this->layout, $narrow);
            $this->assertInstanceOf(ProcPanel::class, $r->panel);
            $p = $r->panel;
        }
        $this->assertNotNull($p->detail());
        $p = $p->update(new KeyMsg(KeyType::Down), $ctx)->panel;
        $p = $p->update(new KeyMsg(KeyType::End), $ctx)->panel;
        $this->assertInstanceOf(ProcPanel::class, $p);
        $this->assertSame($ctx->procSelectMax(), $p->selection()->selected, 'no 8-row detail deduction: the painter dropped it');
        $this->assertArrayNotHasKey('enter', ProcView::buttons(28, $narrow->height, $this->config, false, 0, $p->detail(), null));
        $this->assertTrue(ProcView::detailFits(30, 14));
        $this->assertFalse(ProcView::detailFits(30, 13));
    }

    public function testDetailMarksAVanishedProcessDead(): void
    {
        $a = new ProcSnapshot([ProcRows::process(1), ProcRows::process(5, ['ppid' => 1, 'state' => 'R'])], 1, 100);
        $b = new ProcSnapshot([ProcRows::process(1)], 1, 100);
        $p = $this->sample(ProcPanel::new(self::replay([$a, $a, $b])));
        while ($p->selectedPid($this->config) !== 5) {
            $p = $this->press($p, 'down');
        }
        $p = $this->press($p, 'enter');
        $p = $this->send($p, ($this->last?->cmd)());
        $this->assertSame('running', $p->detail()?->status());
        $this->assertSame('p1', $p->detail()?->parent);
        $p = $this->sample($p);
        $this->assertSame('dead', $p->detail()?->status());
        $this->assertFalse($p->detail()?->alive);
    }

    // ---- mouse -----------------------------------------------------------------

    public function testWheelScrollsInsideTheListOnly(): void
    {
        $p = $this->demo(100);
        $p = $this->send($p, $this->mouse(10, 5, MouseButton::WheelDown));
        $this->assertSame(3, $p->selection()->start);
        $p = $this->send($p, $this->mouse(10, 5, MouseButton::WheelUp));
        $this->assertSame(0, $p->selection()->start);
        $this->assertSame($p, $this->send($p, $this->mouse(10, 0, MouseButton::WheelDown)), 'on the border: ignored');
        $this->assertSame($p, $this->send($p, new MouseMsg($this->box->x + 11, $this->box->y + 6, MouseButton::WheelDown, MouseAction::Press, shift: true)), 'a modified wheel is not btop\'s scroll');
    }

    public function testClickSelectsAndAClickOnTheSelectionOpensDetails(): void
    {
        $p = $this->demo();
        $p = $this->send($p, $this->mouse(10, 4)); // row 3 is two below the header
        $this->assertSame(3, $p->selection()->selected);
        $p = $this->send($p, $this->mouse(20, 4));
        $this->assertSame(['show_detailed' => true], $this->last?->set);
        $this->assertNotNull($p->detail());
    }

    public function testClickOnTheTreeMarkerTogglesTheBranch(): void
    {
        $p = $this->treeAt(412);
        $row = $p->selection()->selected;
        $depth = $p->rows($this->config)[$p->selection()->index() ?? 0]->depth;
        $p = $this->send($p, $this->mouse($depth * 3 + 2, $row + 1));
        $this->assertTrue($p->collapsed()[412] ?? false);
        $this->assertNull($p->detail(), 'the marker toggles instead of opening details');
    }

    public function testClickOutsideTheListClearsTheSelection(): void
    {
        $p = $this->press($this->demo(), 'down', 'down');
        $p = $this->send($p, new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press));
        $this->assertSame(0, $p->selection()->selected);
    }

    public function testOtherButtonsAndModifiedClicksAreIgnored(): void
    {
        $p = $this->demo();
        $this->assertSame($p, $this->send($p, $this->mouse(10, 4, MouseButton::Right)));
        $this->assertSame($p, $this->send($p, new MouseMsg($this->box->x + 11, $this->box->y + 5, MouseButton::Left, MouseAction::Press, ctrl: true)));
        $this->assertSame($p, $this->send($p, $this->mouse(10, 4, MouseButton::None, MouseAction::Motion)), 'motion without a drag');
    }

    public function testScrollbarArrowsPageAndTheTrackJumpsProportionally(): void
    {
        $p = $this->demo(100);
        $max = $this->ctx()->procSelectMax();
        $n = count($p->rows($this->config));
        $bar = $this->box->width - 2;
        $height = $this->box->height;
        $p = $this->send($p, $this->mouse($bar, $height - 2));
        $this->assertSame($max, $p->selection()->start, 'down arrow pages');
        $p = $this->send($p, $this->mouse($bar, 1));
        $this->assertSame(0, $p->selection()->start, 'up arrow pages back');
        $y = 7;
        $p = $this->send($p, $this->mouse($bar, 2 + $y));
        $this->assertSame((int) min($n - $max, max(0, round($y * ($n - $max - 2) / ($max - 2)))), $p->selection()->start);
    }

    public function testDraggingTheThumb(): void
    {
        $p = $this->demo(100);
        $bar = $this->box->width - 2;
        $p = $this->send($p, $this->mouse($bar, 2)); // thumb sits on the first track row at start 0
        $this->assertSame(0, $p->selection()->start);
        $p = $this->send($p, $this->mouse($bar, 12, MouseButton::Left, MouseAction::Motion));
        $this->assertGreaterThan(0, $p->selection()->start);
        $at = $p->selection()->start;
        $p = $this->send($p, $this->mouse($bar, 12, MouseButton::Left, MouseAction::Release));
        $p = $this->send($p, $this->mouse($bar, 20, MouseButton::Left, MouseAction::Motion));
        $this->assertSame($at, $p->selection()->start, 'release ends the drag');
    }

    public function testTitleButtonsActAsTheirKeys(): void
    {
        $this->resize(180, 50);
        $p = $this->demo();
        $map = ProcView::buttons($this->box->width, $this->box->height, $this->config, false, 0, null, null);
        foreach (['r' => ['proc_reversed' => true], 'e' => ['proc_tree' => true], 'c' => ['proc_per_core' => true], 'O' => ['proc_filter_containers' => true], 'right' => ['proc_sorting' => 'io read'], 'f' => ['proc_filtering' => true]] as $key => $set) {
            $this->config = Config::new();
            [$x, $y] = $map[$key];
            $this->send($p, $this->mouse($x, $y));
            $this->assertSame($set, $this->last?->set, $key);
        }
        $this->config = Config::new();
        [$x, $y] = $map['left'];
        $this->send($p, $this->mouse($x + 1, $y));
        $this->assertSame(['proc_sorting' => 'cpu direct'], $this->last?->set);
    }

    public function testInfoAndHideButtons(): void
    {
        $p = $this->press($this->demo(), 'down');
        $map = ProcView::buttons($this->box->width, $this->box->height, $this->config, false, 1, null, $p->selectedPid($this->config));
        [$x, $y] = $map['info_enter'];
        $p = $this->send($p, $this->mouse($x, $y));
        $this->assertSame(['show_detailed' => true], $this->last?->set);
        $map = ProcView::buttons($this->box->width, $this->box->height, $this->config, false, 0, $p->detail(), null);
        [$x, $y] = $map['enter'];
        $this->send($p, $this->mouse($x, $y));
        $this->assertSame(['show_detailed' => false], $this->last?->set);
    }

    public function testButtonMapFollowsWidthAndState(): void
    {
        $narrow = ProcView::buttons(44, 20, Config::new(), false, 0, null, null);
        $this->assertArrayNotHasKey('O', $narrow);
        $this->assertArrayNotHasKey('c', $narrow);
        $this->assertArrayNotHasKey('info_enter', $narrow, 'info needs a selection');
        $wide = ProcView::buttons(130, 40, Config::new()->with('proc_filter', 'ab'), false, 2, null, 7);
        $this->assertSame([10, 0, 4], $wide['f']);
        $this->assertSame([15, 0, 3], $wide['delete']);
        $this->assertArrayHasKey('O', $wide);
        $this->assertArrayNotHasKey('f', ProcView::buttons(130, 40, Config::new(), true, 0, null, null), 'no filter button while typing');
    }

    public function testEntriesExposeTheirSampledProcess(): void
    {
        $e = ProcRows::entry(9, ['ioR' => 5.0, 'ioW' => -1.0]);
        $this->assertSame(9, $e->pid());
        $this->assertSame(5.0, $e->ioTotal());
        $this->assertInstanceOf(ProcEntry::class, $e->with(['prefix' => 'x']));
    }
}
