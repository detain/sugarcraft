<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Top\App;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\State\TreeState;
use SugarCraft\Top\State\TreeStateFile;
use SugarCraft\Top\State\TreeStateFlushMsg;
use SugarCraft\Top\State\TreeStateSavedMsg;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\ProcRows;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;

/**
 * #1791c proc_tree_persist_state: remembering collapse choices by
 * process-name ancestry, restoring them on new pids, the debounced and
 * exit-time writes to an injected (temp) state file.
 */
final class ProcPanelTreeStateTest extends TestCase
{
    private Config $config;
    private Layout $layout;
    private Rect $box;
    private ?PanelResult $last = null;
    private string $dir;

    protected function setUp(): void
    {
        $this->config = Config::new()->with('proc_tree', true)->with('proc_tree_persist_state', true)->with('proc_sorting', 'pid');
        $this->layout = FrameBuilder::layout(120, 40, $this->config, 8);
        $box = $this->layout->box('proc');
        $this->assertNotNull($box);
        $this->box = $box;
        $this->dir = sys_get_temp_dir() . '/candy-top-u4-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/tree-state.json';
    }

    /**
     * A source replaying fixed snapshots (the last one repeats).
     *
     * @param list<list<array{0: int, 1: int, 2: string}>> $frames [pid, ppid, name] per process
     */
    private static function replay(array $frames): Source
    {
        $snaps = array_map(
            static fn (array $procs): ProcSnapshot => new ProcSnapshot(
                array_map(static fn (array $p): \SugarCraft\Top\Collect\Process => ProcRows::process($p[0], ['ppid' => $p[1], 'name' => $p[2]]), $procs),
                4,
                8 * 1024 * 1024 * 1024,
            ),
            $frames,
        );

        return new class ($snaps, 0) implements Source {
            /** @param list<ProcSnapshot> $snaps */
            public function __construct(private array $snaps, private int $i)
            {
            }

            public function sample(): array
            {
                return [$this->snaps[min($this->i, count($this->snaps) - 1)], new self($this->snaps, $this->i + 1)];
            }
        };
    }

    private function ctx(): PanelContext
    {
        return new PanelContext($this->config, $this->layout, $this->box);
    }

    private function send(ProcPanel $p, Msg $msg): ProcPanel
    {
        $this->last = $p->update($msg, $this->ctx());
        foreach ($this->last->set as $k => $v) {
            $this->config = $this->config->with($k, $v);
        }
        $this->assertInstanceOf(ProcPanel::class, $this->last->panel);

        return $this->last->panel;
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

    private function select(ProcPanel $p, int $pid): ProcPanel
    {
        for ($i = 0; $i < 50 && $p->selectedPid($this->config) !== $pid; $i++) {
            $p = $this->send($p, new KeyMsg(KeyType::Down));
        }
        $this->assertSame($pid, $p->selectedPid($this->config));

        return $p;
    }

    private static function char(string $c): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $c);
    }

    /** btop's own test: systemd → chromium → chromium, the inner one collapsed. */
    private const BEFORE = [[1, 0, 'systemd'], [10, 1, 'chromium'], [11, 10, 'chromium'], [12, 11, 'renderer']];
    private const RECREATED = [[1, 0, 'systemd'], [50, 1, 'chromium'], [51, 50, 'chromium'], [52, 51, 'renderer'], [60, 1, 'chromium'], [61, 60, 'zygote']];

    private function stored(): ProcPanel
    {
        return ProcPanel::new(self::replay([self::BEFORE]))->withTreeStore(TreeStateFile::new($this->path()), TreeState::empty());
    }

    public function testCollapseIsRememberedByNameAncestryAndArmsADebouncedSave(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $this->assertTrue($p->collapsed()[11]);
        $this->assertTrue($p->treeState()->lookup("systemd\x1fchromium\x1fchromium"));
        $this->assertTrue($p->treeStateDirty());

        $tick = ($this->last?->cmd)();
        $this->assertInstanceOf(TickRequest::class, $tick);
        $this->assertSame(ProcPanel::TREE_SAVE_DELAY, $tick->seconds);
        $flush = ($tick->produce)();
        $this->assertInstanceOf(TreeStateFlushMsg::class, $flush);
        $this->assertFileDoesNotExist($this->path(), 'nothing written before the debounce fires');

        $p = $this->send($p, $flush);
        $saved = Cmds::of(TreeStateSavedMsg::class, $this->last?->cmd);
        $this->assertCount(1, $saved);
        $this->assertTrue($saved[0]->ok);
        $this->assertFileExists($this->path());
        $this->assertTrue(TreeStateFile::new($this->path())->load(time())->lookup("systemd\x1fchromium\x1fchromium"));

        $p = $this->send($p, $saved[0]);
        $this->assertFalse($p->treeStateDirty());
        $this->assertNull($p->treeStateSave($this->config), 'nothing left to write');
    }

    public function testAnOutdatedFlushTickDoesNotWrite(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $first = (($this->last?->cmd)()->produce)();
        $p = $this->send($p, self::char('+'));
        $this->assertFalse($p->treeState()->lookup("systemd\x1fchromium\x1fchromium"), 'the expand replaced the collapse');
        $p = $this->send($p, $first);
        $this->assertNull($this->last?->cmd, 'a newer change re-armed the debounce');
        $this->assertFileDoesNotExist($this->path());
    }

    public function testAFailedOrStaleSaveKeepsTheStateDirty(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $p = $this->send($p, new TreeStateSavedMsg(1, false, 'disk full'));
        $this->assertTrue($p->treeStateDirty());
        $p = $this->send($p, self::char('space'));
        $p = $this->send($p, new TreeStateSavedMsg(1, true));
        $this->assertTrue($p->treeStateDirty(), 'revision 2 has not reached disk');
        $p = $this->send($p, new TreeStateSavedMsg(2, true));
        $this->assertFalse($p->treeStateDirty());
    }

    public function testRestartRestoresTheChoiceOnRecreatedPids(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $this->assertNotNull($save = $p->treeStateSave($this->config));
        $save();

        $loaded = TreeStateFile::new($this->path())->load(time());
        $q = $this->sample(ProcPanel::new(self::replay([self::RECREATED]))->withTreeStore(TreeStateFile::new($this->path()), $loaded));
        $this->assertTrue($q->collapsed()[51] ?? false, 'systemd→chromium→chromium again');
        $this->assertFalse($q->collapsed()[50] ?? false);
        $this->assertFalse($q->collapsed()[60] ?? false);
        $this->assertNotContains(52, array_map(static fn ($r): int => $r->pid(), $q->rows($this->config)), 'the restored branch hides its child');
        $this->assertContains(61, array_map(static fn ($r): int => $r->pid(), $q->rows($this->config)));
    }

    public function testChildrenToggleRemembersEveryDirectChild(): void
    {
        $p = $this->select($this->sample($this->stored()), 1);
        $p = $this->send($p, self::char('C'));
        $this->assertTrue($p->treeState()->lookup("systemd\x1fchromium"));
        $this->assertNull($p->treeState()->lookup('systemd'), 'C does not touch the parent');
    }

    public function testCollapseAllIsNotRememberedNorUndoneByTheNextSample(): void
    {
        $p = $this->select($this->sample($this->stored()), 1);
        $p = $this->send($p, self::char('E'));
        $this->assertSame(0, $p->treeState()->count());
        $this->assertNull($this->last?->cmd);
        $collapsed = $p->collapsed();
        $p = $this->sample($p);
        $this->assertSame($collapsed, $p->collapsed());
    }

    public function testNewProcessesGetTheirRememberedChoiceWhenTheyAppear(): void
    {
        $state = TreeState::empty()->remember("systemd\x1fchromium", true);
        $p = ProcPanel::new(self::replay([[[1, 0, 'systemd'], [5, 1, 'sh']], self::RECREATED]))
            ->withTreeStore(null, $state);
        $p = $this->sample($p);
        $this->assertSame([], array_filter($p->collapsed()));
        $p = $this->sample($p);
        $this->assertTrue($p->collapsed()[50]);
        $this->assertTrue($p->collapsed()[60], 'siblings with the same name chain share the choice');
        // The user expands one; the following sample does not re-collapse it.
        $p = $this->send($this->select($p, 50), self::char('+'));
        $p = $this->sample($p);
        $this->assertFalse($p->collapsed()[50]);
    }

    public function testRememberedExpandBeatsAutoCollapseOnEnteringTreeView(): void
    {
        $this->config = $this->config->with('proc_tree', false)->with('proc_tree_auto_collapse', 1);
        $state = TreeState::empty()->remember("systemd\x1fchromium\x1fchromium", false);
        $p = $this->sample(ProcPanel::new(self::replay([self::BEFORE]))->withTreeStore(null, $state));
        $p = $this->send($p, self::char('e'));
        $this->assertTrue($this->config->bool('proc_tree'));
        $this->assertFalse($p->collapsed()[11], 'restore runs after the auto-collapse');
    }

    public function testOptionOffNeitherRemembersNorRestores(): void
    {
        $this->config = $this->config->with('proc_tree_persist_state', false);
        $state = TreeState::empty()->remember("systemd\x1fchromium", true);
        $p = $this->sample(ProcPanel::new(self::replay([self::BEFORE]))->withTreeStore(TreeStateFile::new($this->path()), $state));
        $this->assertSame([], array_filter($p->collapsed()));
        $p = $this->send($this->select($p, 11), self::char('-'));
        $this->assertNull($p->treeState()->lookup("systemd\x1fchromium\x1fchromium"));
        $this->assertNull($this->last?->cmd);
        $this->assertFalse($p->treeStateDirty());
        $this->assertNull($p->treeStateSave($this->config));

        // Turned on mid-session: the next sample restores every entry.
        $this->config = $this->config->with('proc_tree_persist_state', true);
        $p = $this->sample($p);
        $this->assertTrue($p->collapsed()[10]);
    }

    public function testRestoredMatchesRefreshTheirAgeForTheExitSave(): void
    {
        $old = time() - 1000;
        $state = TreeState::fromArray(['version' => 1, 'entries' => [['path' => ['systemd', 'chromium'], 'collapsed' => true, 'at' => $old]]], time());
        $p = $this->sample(ProcPanel::new(self::replay([self::BEFORE]))->withTreeStore(TreeStateFile::new($this->path()), $state));
        $this->assertTrue($p->treeStateDirty());
        $this->assertNull($this->last?->cmd, 'no debounce for a refresh; the exit save writes it');
        ($p->treeStateSave($this->config))();
        $at = json_decode((string) file_get_contents($this->path()), true)['entries'][0]['at'];
        $this->assertGreaterThan($old, $at);
    }

    public function testNoFileMeansSessionMemoryOnly(): void
    {
        $p = $this->sample(ProcPanel::new(self::replay([self::BEFORE])));
        $p = $this->send($this->select($p, 11), self::char('-'));
        $this->assertTrue($p->treeState()->lookup("systemd\x1fchromium\x1fchromium"));
        $this->assertNull($this->last?->cmd, 'no file: no save tick');
        $this->assertNull($p->treeStateSave($this->config));
    }

    public function testFlushAndSavedMessagesWorkWhileTheBoxIsHidden(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $result = $p->update(new TreeStateFlushMsg(1), new PanelContext($this->config, $this->layout, null));
        $this->assertNotNull($result->cmd);
    }

    /** Review repro: a restore match between a change and its flush tick must not swallow the write. */
    public function testRestoreMatchBetweenChangeAndFlushKeepsTheDebouncedWrite(): void
    {
        $now = time();
        $loaded = TreeState::fromArray(['version' => 1, 'entries' => [['path' => ['systemd', 'chromium', 'zygote'], 'collapsed' => true, 'at' => $now - 100]]], $now);
        $frames = [self::BEFORE, [...self::BEFORE, [60, 1, 'chromium'], [61, 60, 'zygote']]];
        $p = ProcPanel::new(self::replay($frames))->withTreeStore(TreeStateFile::new($this->path()), $loaded);
        $p = $this->select($this->sample($p), 11);
        $p = $this->send($p, self::char('-'));
        $flush = (($this->last?->cmd)()->produce)();
        $p = $this->sample($p); // new pid 61 matches the loaded key: its age is refreshed
        $this->assertNull($this->last?->cmd, 'a refresh arms no tick of its own');
        $p = $this->send($p, $flush);
        $saved = Cmds::of(TreeStateSavedMsg::class, $this->last?->cmd);
        $this->assertCount(1, $saved, 'the pending flush still writes');
        $this->assertTrue($saved[0]->ok);
        $p = $this->send($p, $saved[0]);
        $this->assertFalse($p->treeStateDirty(), 'the write carried the refresh too');
        $stored = TreeStateFile::new($this->path())->load(time());
        $this->assertTrue($stored->lookup("systemd\x1fchromium\x1fchromium"));
        $this->assertTrue($stored->lookup("systemd\x1fchromium\x1fzygote"));
    }

    public function testTurningTheOptionOffBeforeTheSaveDropsThePendingWrite(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $flush = (($this->last?->cmd)()->produce)();
        $this->config = $this->config->with('proc_tree_persist_state', false);
        $p = $this->send($p, $flush);
        $this->assertNull($this->last?->cmd, 'pending tick: no write with the option off');
        $this->assertNull($p->treeStateSave($this->config), 'exit save: no write either');
        $app = App::start($this->config, ThemeConfig::new(), Harness::host(), ['proc' => $p]);
        $this->assertNull($app->stateSave());
        $this->assertFileDoesNotExist($this->path());
    }

    public function testAppQuitWritesThePendingTreeState(): void
    {
        $p = $this->select($this->sample($this->stored()), 11);
        $p = $this->send($p, self::char('-'));
        $host = Harness::host();
        $app = App::start($this->config, ThemeConfig::new(), $host, ['proc' => $p]);
        $this->assertNotNull($app->stateSave());
        $msgs = Cmds::run($app->quitCmd());
        $this->assertInstanceOf(TreeStateSavedMsg::class, $msgs[0]);
        $this->assertInstanceOf(QuitMsg::class, $msgs[1]);
        $this->assertTrue(TreeStateFile::new($this->path())->load(time())->lookup("systemd\x1fchromium\x1fchromium"));

        $clean = App::start($this->config, ThemeConfig::new(), $host, ['proc' => $this->sample($this->stored())]);
        $this->assertNull($clean->stateSave());
        $this->assertInstanceOf(QuitMsg::class, Cmds::run($clean->quitCmd())[0]);
    }
}
