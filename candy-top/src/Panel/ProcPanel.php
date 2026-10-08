<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Dash\Plot\ProcRow\ProcGraphTracker;
use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\TunableProcList;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Overlay\Menus;
use SugarCraft\Top\Overlay\Overlay;
use SugarCraft\Top\Overlay\ReniceMenu;
use SugarCraft\Top\Overlay\SignalMenu;
use SugarCraft\Top\Overlay\Signals;
use SugarCraft\Top\Panel\Proc\DetailState;
use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Top\Input\TextKeys;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Proc\ProcSelection;
use SugarCraft\Top\Panel\Proc\ProcTable;
use SugarCraft\Top\Panel\Proc\ProcTree;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/**
 * btop's proc box (phase P-E): the process list with sorting, the filter
 * prompt, tree view, keyboard / wheel / click / scrollbar navigation and
 * the detailed view.
 *
 * Data flow: {@see collect()} configures the source from the CURRENT
 * config — proc_per_core, proc_filter_kernel, #1823 io (only while the
 * IO/R IO/W columns are on screen, width >= 90, or an io sort is active)
 * and the detailed pid (#1546 cwd, elapsed, io totals are read for that
 * one pid only) — and samples it inside the Cmd. {@see update()} folds the
 * snapshot in: an UNMEASURED cpu reading keeps the pid's last value
 * (#1008; io is never carried — UNMEASURED io renders "-"), the entries keep the previous frame's order so sort ties stay
 * put, the rows are rebuilt ({@see ProcTable}) and the drawn rows' cpu
 * mini-graphs and the detailed view's history advance.
 *
 * Keys (btop_input.cpp:297-534), all only while the box is shown:
 * up/down (j/k with vim_keys), page up/down, home/end (g/G), left/right
 * (h/l) cycle proc_sorting, `r` proc_reversed, `c` proc_per_core, `%`
 * proc_mem_bytes, `e` proc_tree, `E` collapse/expand all, `+`/`=`/`-`/
 * space/`C` expand/collapse/toggle (tree view, a row selected — the only
 * keys {@see capturesKey()} claims), `O` proc_filter_containers (#1873),
 * `f` or `/` opens the filter (then {@see modal()}: every key is text;
 * Enter/Down keep it, Esc or a click restores the old one; `!` makes it a
 * regex), delete clears it, Enter opens / closes the detailed view. Every
 * option a key changes is written through {@see PanelResult::$set}.
 * Phase P-F1: `t` / `k` (`K` with vim_keys) ask to send SIGTERM / SIGKILL,
 * `s` opens the signal chooser and `N` the renice menu — all as overlays
 * the App opens ({@see PanelResult::$overlay}), targeting the selected row
 * or, with nothing selected, the detailed process while it is alive; the
 * signal / renice itself runs in a Cmd through the injected
 * {@see ProcessControl}. `u` flips pause_proc_list (the list stops taking
 * new samples), `F` follows the selected (or detailed) process: the list
 * keeps it centred and highlighted until it exits, the selection moves
 * (unless paused) or `F` is pressed again. Either shows btop's banner on
 * the list's last row, which then holds one process fewer.
 *
 * Mouse: wheel scrolls by 3, a click selects (a click on the selected row
 * opens the detailed view, or toggles its tree branch when it lands on the
 * `[-]` marker), the scrollbar column pages at its arrows, drags from its
 * thumb and jumps proportionally elsewhere, title buttons act as their
 * keys, and a click outside the list clears the selection. The buttons
 * are claimed through {@see ClickCapture}, so the App hands a click on
 * one to this box alone.
 *
 * Mirrors aristocratos/btop Proc::draw / Proc::selection
 * (src/btop_draw.cpp), Input::process's proc block (src/btop_input.cpp)
 * and Proc::collect's post-processing (src/linux/btop_collect.cpp).
 */
final class ProcPanel implements Panel, ClickCapture
{
    /** btop Proc::draw clears dead pids' graphs every 100 fresh frames. */
    private const SWEEP = 100;

    /**
     * @param list<ProcEntry> $entries every sampled process in the last sorted order
     * @param array<int, float> $carry pid => last good cpu (#1008)
     * @param list<ProcEntry> $rows the visible rows for {@see $rowsKey}
     * @param array<int, bool> $collapsed tree-view collapse state by pid
     */
    private function __construct(
        private Source $source,
        private ?ProcSnapshot $snapshot = null,
        private array $entries = [],
        private array $carry = [],
        private int $memTotal = 0,
        private array $rows = [],
        private string $rowsKey = '',
        private ?ProcSelection $sel = null,
        private array $collapsed = [],
        private int $treeVersion = 0,
        private ?TextEdit $edit = null,
        private string $oldFilter = '',
        private ?DetailState $detail = null,
        private ?ProcGraphTracker $graphs = null,
        private int $sweep = 0,
        private bool $dragging = false,
        private ?ProcessControl $control = null,
        private ?int $followedPid = null,
        private int $followRow = 0,
        private bool $returnToFollowed = false,
        private bool $bannerShown = false,
    ) {
        $this->sel ??= ProcSelection::new();
        $this->graphs ??= ProcGraphTracker::new();
        $this->control ??= FakeProcessControl::new();
    }

    /**
     * @param ?ProcessControl $control what the signal / renice menus act through. Null is the
     *                                 inert {@see FakeProcessControl}: a live process is only ever
     *                                 signalled when the caller passes
     *                                 {@see \SugarCraft\Top\Collect\PosixProcessControl} explicitly
     *                                 ({@see Panels::standard()} does, outside `--fake`).
     */
    public static function new(Source $source, ?ProcessControl $control = null): self
    {
        return new self($source, control: $control);
    }

    /** The followed process (btop followed_pid), or null when not following. */
    public function followedPid(): ?int
    {
        return $this->followedPid;
    }

    public function box(): string
    {
        return 'proc';
    }

    /** The latest snapshot, null before the first sample. */
    public function snapshot(): ?ProcSnapshot
    {
        return $this->snapshot;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function selection(): ProcSelection
    {
        return $this->sel ?? ProcSelection::new();
    }

    /**
     * The rows the list shows under `$config` (memoised for the config the
     * panel last saw).
     *
     * @return list<ProcEntry>
     */
    public function rows(Config $config): array
    {
        return ProcTable::key($config, $this->treeVersion) === $this->rowsKey
            ? $this->rows
            : ProcTable::build($this->entries, $config, $this->collapsed)[0];
    }

    /** The selected row's pid, or null (btop selected_pid). */
    public function selectedPid(Config $config): ?int
    {
        $i = $this->selection()->index();

        return $i === null ? null : ($this->rows($config)[$i] ?? null)?->pid();
    }

    public function detail(): ?DetailState
    {
        return $this->detail;
    }

    public function filterEdit(): ?TextEdit
    {
        return $this->edit;
    }

    /** @return array<int, bool> */
    public function collapsed(): array
    {
        return $this->collapsed;
    }

    public function graphs(): ProcGraphTracker
    {
        return $this->graphs ?? ProcGraphTracker::new();
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $source = $this->configured($context->config, $context->box?->width ?? 0);

        return static function () use ($source): Msg {
            [$snapshot, $next] = $source->sample();

            return new SampledMsg('proc', $snapshot, $next);
        };
    }

    public function modal(PanelContext $context): bool
    {
        return $context->visible() && $context->config->bool('proc_filtering');
    }

    /** `+`/`-`/`=`/space/`C` while proc_tree is on and a row is selected (btop_input.cpp:491). */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        if (!$context->visible() || !$context->config->bool('proc_tree') || $this->selection()->selected === 0) {
            return false;
        }

        return in_array(self::keyName($key, false), ['+', '-', '=', 'space', 'C'], true);
    }

    /** A click on one of the box's painted buttons belongs to this box alone. */
    public function capturesClick(MouseMsg $msg, PanelContext $context): bool
    {
        return !$context->config->bool('proc_filtering') && $this->buttonAt($msg, $context) !== null;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg) {
            return $msg->box === 'proc' && $msg->snapshot instanceof ProcSnapshot
                ? new PanelResult($this->sampled($msg->snapshot, $msg->next, $context))
                : new PanelResult($this);
        }
        if (!$context->visible()) {
            return new PanelResult($this);
        }
        if ($msg instanceof KeyMsg) {
            return $context->config->bool('proc_filtering') ? $this->filterKey($msg, $context) : $this->key($msg, $context);
        }
        if ($msg instanceof MouseMsg) {
            if ($context->config->bool('proc_filtering')) {
                // The App only lets bare left clicks through while filtering:
                // btop treats one like escape (restore the old filter).
                return $this->closeFilter($context, $this->oldFilter);
            }

            return $this->mouse($msg, $context);
        }
        if ($msg instanceof WindowSizeMsg) {
            return new PanelResult($this->settled($context->config, $context));
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        $config = $frame->config;
        $detail = $config->bool('show_detailed') ? $this->detail : null;
        ProcView::paint(
            $region,
            $frame,
            $this->rows($config),
            $this->selection(),
            $config->bool('proc_filtering') ? ($this->edit ?? TextEdit::new($config->string('proc_filter'))) : null,
            $this->graphs(),
            $detail,
            $this->memTotal,
            $this->snapshot?->coreCount ?? 1,
            $this->followedPid,
            $this->followRow,
            $this->returnToFollowed,
        );
    }

    // ---- sampling -----------------------------------------------------------

    /** The source with this frame's opt-ins applied. */
    private function configured(Config $config, int $width): Source
    {
        $io = $width >= 90 || str_starts_with($config->procSorting(), 'io ');
        $detailPid = $config->bool('show_detailed') ? $this->detail?->pid : null;
        $perCore = $config->bool('proc_per_core');
        $kernel = $config->bool('proc_filter_kernel');
        $s = $this->source;
        // The interface, not Collect\ProcList: FreeBSD's collector retunes too.
        if ($s instanceof CollectorSource && ($c = $s->collector()) instanceof TunableProcList) {
            return CollectorSource::of($c->withIo($io)->withPerCore($perCore)->withFilterKernel($kernel)->withDetail($detailPid));
        }
        if ($s instanceof FakeProcList) {
            return $s->withIo($io)->withPerCore($perCore)->withFilterKernel($kernel)->withDetail($detailPid);
        }

        return $s;
    }

    private function sampled(ProcSnapshot $snap, Source $next, PanelContext $context): self
    {
        $config = $context->config;
        if ($config->bool('pause_proc_list')) {
            // btop pause_proc_list: the list and its values stay as they
            // were; only the detailed view keeps following its pid.
            $detail = $this->detail;
            if ($detail !== null) {
                $fresh = null;
                foreach ($snap->processes as $p) {
                    if ($p->pid === $detail->pid) {
                        $fresh = ProcEntry::of($p, $p->cpu >= 0.0 ? $p->cpu : ($this->carry[$p->pid] ?? 0.0), $p->ioRead, $p->ioWrite);
                        break;
                    }
                }
                $detail = $detail->observe($fresh, $snap->detail?->pid === $detail->pid ? $snap->detail : null, $snap->coreCount, $config->bool('proc_per_core'), $this->memTotal, $detail->parent);
            }

            return $this->mutate(['source' => $next, 'snapshot' => $snap, 'detail' => $detail])->settled($config, $context);
        }
        $previous = [];
        foreach ($this->entries as $i => $e) {
            $previous[$e->pid()] = $i;
        }
        $carry = [];
        $fresh = [];
        foreach ($snap->processes as $p) {
            $cpu = $p->cpu >= 0.0 ? $p->cpu : ($this->carry[$p->pid] ?? 0.0);
            $carry[$p->pid] = $cpu;
            // io is never carried: an UNMEASURED rate means the collector
            // was not reading io (columns off), the file is unreadable
            // (EACCES) or this is the pid's first io sample — all "-".
            $fresh[$p->pid] = ProcEntry::of($p, $cpu, $p->ioRead, $p->ioWrite);
        }
        // Keep the previous order (btop sorts its persistent vector in
        // place); new pids join at the end in scan order.
        $kept = array_intersect_key($previous, $fresh);
        asort($kept);
        $entries = [];
        foreach (array_keys($kept) as $pid) {
            $entries[] = $fresh[$pid];
        }
        foreach ($fresh as $pid => $e) {
            if (!isset($kept[$pid])) {
                $entries[] = $e;
            }
        }

        $next = $this->mutate([
            'source' => $next,
            'snapshot' => $snap,
            'entries' => $entries,
            'carry' => $carry,
            'memTotal' => $snap->memTotal > 0 ? $snap->memTotal : $this->memTotal,
            'rowsKey' => '',
        ])->rebuilt($config)->settled($config, $context);

        // Mini-graphs: one observation per fresh frame for the drawn rows.
        $graphs = $next->graphs();
        if ($config->bool('proc_cpu_graphs')) {
            $family = ProcView::family($config);
            if ($graphs->family() !== $family) {
                $graphs = ProcGraphTracker::new(5, $family);
            }
            $sel = $next->selection();
            foreach (array_slice($next->rows, $sel->start, max(0, $next->selectMax($config, $context))) as $row) {
                $graphs = $graphs->observe($row->pid(), $row->cpu);
            }
        }
        $sweep = $this->sweep + 1;
        if ($sweep >= self::SWEEP) {
            $graphs = $graphs->retain(array_keys($fresh));
            $sweep = 0;
        }

        $detail = $this->detail;
        if ($detail !== null) {
            $entry = $fresh[$detail->pid] ?? null;
            $parent = $entry !== null && isset($fresh[$entry->process->ppid]) ? $fresh[$entry->process->ppid]->process->name : '';
            $detail = $detail->observe(
                $entry,
                $snap->detail?->pid === $detail->pid ? $snap->detail : null,
                $snap->coreCount,
                $config->bool('proc_per_core'),
                $next->memTotal,
                $parent,
            );
        }

        return $next->mutate(['graphs' => $graphs, 'sweep' => $sweep, 'detail' => $detail]);
    }

    // ---- keys ---------------------------------------------------------------

    private function key(KeyMsg $msg, PanelContext $context): PanelResult
    {
        $config = $context->config;
        $key = self::keyName($msg, $config->bool('vim_keys'));
        $tree = $config->bool('proc_tree');

        $kill = $config->bool('vim_keys') ? 'K' : 'k';

        return match (true) {
            $key === 't' || $key === $kill => $this->signalKey($context, $key === 't' ? Signals::SIGTERM : Signals::SIGKILL),
            $key === 's' => $this->menuKey($context, static fn (int $pid, string $name, ProcessControl $c): Overlay => SignalMenu::new($pid, $name, $c)),
            $key === 'N' => $this->menuKey($context, static fn (int $pid, string $name, ProcessControl $c): Overlay => ReniceMenu::new($pid, $name, $c)),
            $key === 'u' => $this->set($context, ['pause_proc_list' => !$config->bool('pause_proc_list')]),
            $key === 'F' => $this->follow($context),
            $key === 'left', $key === 'right' => $this->set($context, ['proc_sorting' => self::cycleSort($config->procSorting(), $key === 'right' ? 1 : -1)]),
            $key === 'f', $key === '/' => $this->openFilter($context),
            $key === 'e' => $this->toggleTree($context),
            $key === 'E' && $tree => $this->treeChange($context, ProcTree::toggleAll($this->entries, $this->collapsed)),
            $key === 'r' => $this->set($context, ['proc_reversed' => !$config->bool('proc_reversed')]),
            $key === 'c' => $this->set($context, ['proc_per_core' => !$config->bool('proc_per_core')]),
            $key === '%' => $this->set($context, ['proc_mem_bytes' => !$config->bool('proc_mem_bytes')]),
            $key === 'O' => $this->set($context, ['proc_filter_containers' => !$config->bool('proc_filter_containers')]),
            $key === 'delete' => $config->string('proc_filter') !== '' ? $this->set($context, ['proc_filter' => '']) : new PanelResult($this),
            $key === 'enter' => $this->enter($context),
            in_array($key, ['+', '-', '=', 'space', 'C'], true) && $tree => $this->treeKey($key, $context),
            in_array($key, ['up', 'down', 'page_up', 'page_down', 'home', 'end'], true) => new PanelResult($this->moved($key, $context)),
            default => new PanelResult($this),
        };
    }

    /**
     * btop key names for a KeyMsg: named keys as btop spells them, plain
     * characters as themselves; vim_keys maps h/j/k/l/g/G onto arrows.
     */
    public static function keyName(KeyMsg $msg, bool $vim): string
    {
        if ($msg->ctrl || $msg->alt) {
            return '';
        }
        $name = match ($msg->type) {
            KeyType::Up => 'up',
            KeyType::Down => 'down',
            KeyType::Left => 'left',
            KeyType::Right => 'right',
            KeyType::PageUp => 'page_up',
            KeyType::PageDown => 'page_down',
            KeyType::Home => 'home',
            KeyType::End => 'end',
            KeyType::Enter => 'enter',
            KeyType::Delete => 'delete',
            KeyType::Space => 'space',
            KeyType::Escape => 'escape',
            KeyType::Backspace => 'backspace',
            KeyType::Char => $msg->rune,
            default => '',
        };
        if ($vim) {
            $name = match ($name) {
                'j' => 'down',
                'k' => 'up',
                'g' => 'home',
                'G' => 'end',
                'h' => 'left',
                'l' => 'right',
                default => $name,
            };
        }

        return $name;
    }

    /** btop: left/right step through Proc::sort_vector, wrapping. */
    public static function cycleSort(string $current, int $step): string
    {
        $all = Schema::PROC_SORTING;
        $n = count($all);
        $i = array_search($current, $all, true);
        if ($i === false) {
            // btop v_index() misses: ++ wraps to the first, -- lands on the last.
            return $step > 0 ? $all[0] : $all[$n - 1];
        }

        return $all[(($i + $step) % $n + $n) % $n];
    }

    // ---- signals / renice / follow (phase P-F1) ------------------------------

    /**
     * btop's s_pid: the selected row's process, or — with nothing selected —
     * the detailed one; null when neither applies or the detailed process is
     * dead (btop_input.cpp:505-520).
     *
     * @return array{0: int, 1: string}|null [pid, name]
     */
    public function signalTarget(Config $config): ?array
    {
        $sel = $this->selection();
        $detailShown = $config->bool('show_detailed') && $this->detail !== null;
        if ($sel->selected > 0) {
            $i = $sel->index();
            $row = $i === null ? null : ($this->rows($config)[$i] ?? null);

            return $row === null ? null : [$row->pid(), $row->process->name];
        }
        if (!$detailShown || $this->detail === null || !$this->detail->alive) {
            return null;
        }

        return [$this->detail->pid, $this->detail->entry?->process->name ?? ''];
    }

    /** `t` / `k`: btop Menu::show(SignalSend, SIGTERM | SIGKILL) — a Yes/No box. */
    private function signalKey(PanelContext $context, int $signal): PanelResult
    {
        $target = $this->signalTarget($context->config);
        if ($target === null) {
            return new PanelResult($this);
        }

        return new PanelResult($this, null, [], Menus::signalSend($this->processControl(), $target[0], $target[1], $signal));
    }

    /**
     * `s` / `N`: open the chooser built by `$menu` for the target.
     *
     * @param \Closure(int, string, ProcessControl): Overlay $menu
     */
    private function menuKey(PanelContext $context, \Closure $menu): PanelResult
    {
        $target = $this->signalTarget($context->config);
        if ($target === null) {
            return new PanelResult($this);
        }

        return new PanelResult($this, null, [], $menu($target[0], $target[1], $this->processControl()));
    }

    /** What the signal / renice menus act through. */
    public function processControl(): ProcessControl
    {
        return $this->control ?? FakeProcessControl::new();
    }

    /**
     * `F` (btop_input.cpp:395-418): follow the selected process, else the
     * detailed one; pressed while following, stop — returning the
     * selection to the followed row (or the detailed pid) as btop does.
     */
    private function follow(PanelContext $context): PanelResult
    {
        $config = $context->config;
        $sel = $this->selection();
        $pid = $this->selectedPid($config);
        $detailShown = $config->bool('show_detailed') && $this->detail !== null;
        if ($sel->selected !== 0 && $pid !== null && $this->followedPid !== $pid) {
            return new PanelResult($this->mutate(['followedPid' => $pid])->tracked($config, $context, true)->settled($config, $context));
        }
        if ($detailShown && $sel->selected === 0 && $this->detail !== null && $this->followedPid !== $this->detail->pid) {
            return new PanelResult($this->mutate(['followedPid' => $this->detail->pid])->tracked($config, $context, true)->settled($config, $context));
        }
        if ($this->followedPid === null) {
            return new PanelResult($this);
        }
        $panel = $this->unfollowed();
        if ($this->returnToFollowed) {
            $panel = $panel->mutate(['sel' => $sel->withSelected($this->followRow)]);
        } elseif ($detailShown && $this->detail !== null && $this->followedPid === $this->detail->pid) {
            $panel = $panel->restored($this->detail->pid, $config, $context);
        }

        return new PanelResult($panel->settled($config, $context));
    }

    private function unfollowed(): self
    {
        return $this->mutate(['followedPid' => null, 'followRow' => 0, 'returnToFollowed' => false]);
    }

    /**
     * btop Proc::draw's follow block: centre the list on the followed pid
     * (selected, unless it is the detailed one) or stop following when it
     * is gone. While paused only a forced pass moves it (btop
     * update_following: `F` and the sort / tree keys).
     */
    private function tracked(Config $config, PanelContext $context, bool $force): self
    {
        if ($this->followedPid === null || !$context->visible() || (!$force && $config->bool('pause_proc_list'))) {
            return $this;
        }
        $rows = $this->rows($config);
        $loc = null;
        foreach ($rows as $i => $row) {
            if ($row->pid() === $this->followedPid) {
                $loc = $i + 1;
                break;
            }
        }
        if ($loc === null) {
            return $this->unfollowed();
        }
        $max = max(1, $this->selectMax($config, $context));
        $numpids = count($rows);
        $middle = $max % 2 === 0 ? intdiv($max, 2) : intdiv($max, 2) + 1;
        $start = max(0, $loc - $middle);
        $followed = $loc < $middle ? $loc : ($start > $numpids - $max ? $max - $numpids + $loc : $middle);
        // btop's later bounds check pulls start back to the list end, where
        // `followed` (max - numpids + loc) already points.
        $start = min($start, max(0, $numpids - $max));
        $onDetail = $config->bool('show_detailed') && $this->detail?->pid === $this->followedPid;

        return $this->mutate([
            'sel' => $this->selection()->withStart($start)->withSelected($onDetail ? 0 : $followed),
            'followRow' => $followed,
            'returnToFollowed' => true,
        ]);
    }

    /**
     * btop Proc::selection's preamble: a selection move first returns from
     * the detailed view to the followed row, then — unless paused — stops
     * following.
     */
    private function leaveFollow(Config $config): self
    {
        if ($this->followedPid === null) {
            return $this;
        }
        $panel = $this;
        $sel = $this->selection();
        if ($config->bool('show_detailed') && $sel->selected === 0 && $this->returnToFollowed && $this->detail?->pid === $this->followedPid) {
            $panel = $panel->mutate(['sel' => $sel->withSelected($this->followRow), 'returnToFollowed' => false]);
        }

        return $config->bool('pause_proc_list') ? $panel : $panel->unfollowed();
    }

    private function openFilter(PanelContext $context): PanelResult
    {
        $text = $context->config->string('proc_filter');

        return new PanelResult(
            $this->mutate(['edit' => TextEdit::new($text), 'oldFilter' => $text]),
            null,
            ['proc_filtering' => true],
        );
    }

    private function filterKey(KeyMsg $msg, PanelContext $context): PanelResult
    {
        $edit = $this->edit ?? TextEdit::new($context->config->string('proc_filter'));
        $key = self::keyName($msg, false);
        if ($key === 'enter' || $key === 'down') {
            $result = $this->closeFilter($context, $edit->text);
            if ($key === 'down') {
                $after = $this->after($context->config, $result->set);
                $panel = $result->panel instanceof self ? $result->panel : $this;

                return new PanelResult($panel->moved('down', $context, $after), null, $result->set);
            }

            return $result;
        }
        if ($key === 'escape') {
            return $this->closeFilter($context, $this->oldFilter);
        }
        $next = TextKeys::apply($edit, $msg);
        if ($next === null) {
            return new PanelResult($this);
        }
        $panel = $this->mutate(['edit' => $next]);

        return $next->text !== $context->config->string('proc_filter')
            ? $panel->set($context, ['proc_filter' => $next->text])
            : new PanelResult($panel);
    }

    private function closeFilter(PanelContext $context, string $filter): PanelResult
    {
        return $this->mutate(['edit' => null, 'oldFilter' => ''])
            ->set($context, ['proc_filter' => $filter, 'proc_filtering' => false]);
    }

    private function toggleTree(PanelContext $context): PanelResult
    {
        $on = !$context->config->bool('proc_tree');
        $panel = $this;
        if ($on) {
            $threshold = $context->config->int('proc_tree_auto_collapse');
            $collapsed = ProcTree::autoCollapse($this->entries, $this->collapsed, $threshold);
            if ($collapsed !== $this->collapsed) {
                $panel = $this->mutate(['collapsed' => $collapsed, 'treeVersion' => $this->treeVersion + 1]);
            }
        }

        return $panel->set($context, ['proc_tree' => $on]);
    }

    /** `+` `=` expand, `-` collapse, space toggles, `C` toggles the children (btop_input.cpp:491-503). */
    private function treeKey(string $key, PanelContext $context): PanelResult
    {
        $pid = $this->selectedPid($context->config);
        if ($pid === null) {
            return new PanelResult($this);
        }
        $collapsed = $this->collapsed;
        if ($key === 'C') {
            $collapsed = ProcTree::toggleChildren($this->entries, $collapsed, $pid);
        } else {
            $collapsed[$pid] = match ($key) {
                '-' => true,
                'space' => !($collapsed[$pid] ?? false),
                default => false,
            };
        }

        return $this->treeChange($context, $collapsed);
    }

    /**
     * Apply a new collapse map and keep the selected process under the
     * cursor (btop's locate_selection).
     *
     * @param array<int, bool> $collapsed
     */
    private function treeChange(PanelContext $context, array $collapsed): PanelResult
    {
        $config = $context->config;
        $pid = $this->selectedPid($config);
        $panel = $this->mutate(['collapsed' => $collapsed, 'treeVersion' => $this->treeVersion + 1, 'rowsKey' => ''])->rebuilt($config);
        if ($pid !== null) {
            $panel = $panel->located($pid, $config, $context);
        }

        return new PanelResult($panel->settled($config, $context));
    }

    /** btop locate_selection: keep `$pid` selected after the rows moved. */
    private function located(int $pid, Config $config, PanelContext $context): self
    {
        $loc = null;
        foreach ($this->rows as $i => $row) {
            if ($row->pid() === $pid) {
                $loc = $i;
                break;
            }
        }
        if ($loc === null) {
            return $this;
        }
        $sel = $this->selection();
        $start = $sel->start;
        $max = $this->selectMax($config, $context);
        if ($start >= $loc || $start <= $loc - $max) {
            $start = max(0, $loc - 1);
        }

        return $this->mutate(['sel' => $sel->withStart($start)->withSelected($loc - $start + 1)]);
    }

    /** Enter / info: open the detailed view on the selection, or close it (btop_input.cpp:468-489). */
    private function enter(PanelContext $context): PanelResult
    {
        $config = $context->config;
        $sel = $this->selection();
        $shown = $config->bool('show_detailed') && $this->detail !== null;
        $pid = $this->selectedPid($config);
        if ($sel->selected === 0 && !$shown) {
            return new PanelResult($this);
        }
        if ($sel->selected > 0 && $pid !== null && (!$shown || $this->detail?->pid !== $pid)) {
            $entry = null;
            foreach ($this->entries as $e) {
                if ($e->pid() === $pid) {
                    $entry = $e;
                    break;
                }
            }
            $panel = $this->mutate([
                'detail' => DetailState::open($pid, $entry),
                'sel' => $sel->withLastSelected($sel->selected)->withSelected(0),
            ]);
            if ($config->bool('proc_follow_detailed')) {
                // btop: opening the detailed view follows its process.
                $panel = $panel->mutate(['followedPid' => $pid]);
            }
            $after = $this->after($config, ['show_detailed' => true]);
            $panel = $panel->settled($after, $context);

            // Sample at once so the detail box (cwd, io totals) fills now.
            return new PanelResult($panel, $panel->collect(new PanelContext($after, $context->layout, $context->box)), ['show_detailed' => true]);
        }
        if (!$shown) {
            return new PanelResult($this);
        }
        $detailPid = $this->detail?->pid;
        $panel = $this->mutate(['detail' => null]);
        if ($config->bool('proc_follow_detailed') && $detailPid !== null && $this->followedPid === $detailPid) {
            $panel = $panel->unfollowed();
        }
        if ($config->bool('proc_follow_detailed') && $detailPid !== null) {
            $panel = $panel->restored($detailPid, $this->after($config, ['show_detailed' => false]), $context);
        } elseif ($sel->lastSelected > 0) {
            $panel = $panel->mutate(['sel' => $sel->withSelected($sel->lastSelected)]);
        }
        $panel = $panel->mutate(['sel' => $panel->selection()->withLastSelected(0)]);
        $after = $this->after($config, ['show_detailed' => false]);

        return new PanelResult($panel->settled($after, $context), null, ['show_detailed' => false]);
    }

    /**
     * btop restore_detailed_pid: put the list back around the process the
     * detailed view showed, with it selected (Proc::draw follow block).
     */
    private function restored(int $pid, Config $config, PanelContext $context): self
    {
        $loc = 1;
        $found = false;
        foreach ($this->rows($config) as $row) {
            if ($row->pid() === $pid) {
                $found = true;
                break;
            }
            $loc++;
        }
        if (!$found) {
            return $this;
        }
        $max = max(1, $this->selectMax($config, $context));
        $numpids = count($this->rows($config));
        $middle = $max % 2 === 0 ? intdiv($max, 2) : intdiv($max, 2) + 1;
        $start = max(0, $loc - $middle);
        $followed = $loc < $middle ? $loc : ($start > $numpids - $max ? $max - $numpids + $loc : $middle);

        return $this->mutate(['sel' => $this->selection()->withStart($start)->withSelected($followed)]);
    }

    private function moved(string $key, PanelContext $context, ?Config $config = null): self
    {
        $config ??= $context->config;
        $panel = $this->leaveFollow($config);
        $rows = $panel->rows($config);
        $panel = $panel->mutate(['sel' => $panel->selection()->move($key, count($rows), $panel->selectMax($config, $context))]);

        return $panel->bannerShown !== $panel->banner($config) ? $panel->settled($config, $context) : $panel;
    }

    // ---- mouse --------------------------------------------------------------

    private function mouse(MouseMsg $m, PanelContext $context): PanelResult
    {
        $box = $context->box;
        if ($box === null) {
            return new PanelResult($this);
        }
        $config = $context->config;
        if ($m->action === MouseAction::Release) {
            return new PanelResult($this->dragging ? $this->mutate(['dragging' => false]) : $this);
        }
        $lx = $m->x - 1 - $box->x;
        $ly = $m->y - 1 - $box->y;
        $shown = $this->detailDrawn($config, $context);
        $dy = ProcView::detailRows($shown);
        $listH = $box->height - $dy;
        $row = $ly - $dy;
        $inList = $context->hit($m->x, $m->y) && $lx >= 1 && $lx < $box->width && $row >= 1 && $row < $listH - 1;
        $rows = $this->rows($config);
        $max = $this->selectMax($config, $context);

        if ($m->action === MouseAction::Press && in_array($m->button, [MouseButton::WheelUp, MouseButton::WheelDown], true)) {
            // btop maps only the bare `[<64;` / `[<65;` wheel codes.
            $bare = !$m->shift && !$m->alt && !$m->ctrl;

            return new PanelResult($inList && $bare ? $this->moved($m->button === MouseButton::WheelUp ? 'mouse_scroll_up' : 'mouse_scroll_down', $context) : $this);
        }
        if ($m->action === MouseAction::Motion) {
            if ($this->dragging && $m->button === MouseButton::Left) {
                return new PanelResult($this->mutate(['sel' => $this->selection()->move('mousey' . ($row - 2), count($rows), $max)]));
            }

            return new PanelResult($this);
        }
        if ($m->action !== MouseAction::Press || $m->button !== MouseButton::Left || $m->shift || $m->alt || $m->ctrl) {
            return new PanelResult($this);
        }

        // Title / bottom-row buttons first (btop resolves mouse_mappings before Input::process).
        $sel = $this->selection();
        $button = $this->buttonAt($m, $context);
        if ($button !== null) {
            return $this->key(self::synthetic($button), $context);
        }

        if (!$inList) {
            // btop: an unmapped click clears a selection and, unless
            // paused, stops following — both only inside its
            // `proc_selected > 0` branch; with nothing selected it is a no-op.
            if ($sel->selected === 0) {
                return new PanelResult($this);
            }
            $panel = $config->bool('pause_proc_list') ? $this : $this->unfollowed();

            return new PanelResult($panel->mutate(['sel' => $sel->withSelected(0)]));
        }
        if ($lx < $box->width - 2) {
            if ($sel->selected === $row - 1) {
                if ($config->bool('proc_tree') && $sel->index() !== null) {
                    $depth = ($rows[$sel->index()] ?? null)?->depth ?? 0;
                    $offset = $depth * 3;
                    if ($lx > $offset && $lx < 4 + $offset) {
                        return $this->treeKey('space', $context);
                    }
                }

                return $this->enter($context);
            }

            if ($this->banner($config) && $row === $listH - 2) {
                return new PanelResult($this); // btop: the banner row is not a process
            }
            $panel = $config->bool('pause_proc_list') ? $this : $this->unfollowed();

            return new PanelResult($panel->mutate(['sel' => $sel->withSelected($row - 1)->clamp(count($rows), $panel->selectMax($config, $context))]));
        }
        if ($row === 1) {
            return new PanelResult($this->moved('page_up', $context));
        }
        if ($row === $listH - 2) {
            return new PanelResult($this->moved('page_down', $context));
        }
        if ($row === 2 + ProcSelection::thumb($sel->start, count($rows), $max, $listH)) {
            return new PanelResult($this->mutate(['dragging' => true]));
        }

        return new PanelResult($this->moved('mousey' . ($row - 2), $context));
    }

    /** The proc-box button (btop mouse_mappings key) under a bare left click, or null. */
    private function buttonAt(MouseMsg $m, PanelContext $context): ?string
    {
        $box = $context->box;
        if ($box === null || $m->action !== MouseAction::Press || $m->button !== MouseButton::Left
            || $m->shift || $m->alt || $m->ctrl || !$context->hit($m->x, $m->y)) {
            return null;
        }
        $config = $context->config;
        $lx = $m->x - 1 - $box->x;
        $ly = $m->y - 1 - $box->y;
        $shown = $this->detailDrawn($config, $context);
        $buttons = ProcView::buttons($box->width, $box->height, $config, false, $this->selection()->selected, $shown ? $this->detail : null, $this->selectedPid($config));
        foreach ($buttons as $key => [$bx, $by, $bw]) {
            if ($ly === $by && $lx >= $bx && $lx < $bx + $bw) {
                return (string) $key;
            }
        }

        return null;
    }

    /** The KeyMsg a proc-box button stands for. */
    private static function synthetic(string $key): KeyMsg
    {
        return match ($key) {
            'left' => new KeyMsg(KeyType::Left),
            'right' => new KeyMsg(KeyType::Right),
            'delete' => new KeyMsg(KeyType::Delete),
            'enter', 'info_enter' => new KeyMsg(KeyType::Enter),
            default => new KeyMsg(KeyType::Char, $key),
        };
    }

    // ---- shared -------------------------------------------------------------

    /**
     * Return `$set` with the rows already rebuilt for the config it
     * produces, so the very next paint needs no rebuild.
     *
     * @param array<string, bool|int|string> $set
     */
    private function set(PanelContext $context, array $set): PanelResult
    {
        $after = $this->after($context->config, $set);
        // btop update_following: a sort / tree / reverse change re-centres
        // the followed process even while paused.
        $panel = $this->rebuilt($after)->tracked($after, $context, true);

        return new PanelResult($panel->settled($after, $context), null, $set);
    }

    /**
     * `$config` with `$set` applied the way the App will apply it (an
     * invalid value is skipped).
     *
     * @param array<string, bool|int|string> $set
     */
    private function after(Config $config, array $set): Config
    {
        foreach ($set as $k => $v) {
            try {
                $config = $config->with($k, $v);
            } catch (InvalidOptionValue) {
                continue;
            }
        }

        return $config;
    }

    /** Rows (and the sorted base order) for `$config`, memoised by its key. */
    private function rebuilt(Config $config): self
    {
        $key = ProcTable::key($config, $this->treeVersion);
        if ($key === $this->rowsKey) {
            return $this;
        }
        [$rows, $sorted] = ProcTable::build($this->entries, $config, $this->collapsed);

        return $this->mutate(['rows' => $rows, 'entries' => $sorted, 'rowsKey' => $key]);
    }

    /** Proc::draw's bounds check against the current list and box. */
    private function settled(Config $config, PanelContext $context): self
    {
        if (!$context->visible()) {
            return $this;
        }
        $panel = $this->tracked($config, $context, false);
        $numpids = count($panel->rows($config));
        $max = $panel->selectMax($config, $context);
        $sel = $panel->selection();
        // btop Proc::draw: when the banner goes away at the end of the list
        // the row it held is filled from above, so the selection steps down
        // with it; when a pause banner appears over the selection the list
        // scrolls one instead.
        $banner = $panel->banner($config);
        if ($panel->bannerShown && !$banner && $sel->selected > 0 && $sel->start + $max - 1 === $numpids) {
            $sel = $sel->withSelected($sel->selected + 1);
        } elseif ($config->bool('pause_proc_list') && $sel->selected > $max) {
            $sel = $sel->withStart($sel->start + 1);
        }
        $sel = $sel->clamp($numpids, $max);
        if ($banner !== $panel->bannerShown) {
            $panel = $panel->mutate(['bannerShown' => $banner]);
        }

        return $sel === $panel->sel ? $panel : $panel->mutate(['sel' => $sel]);
    }

    /** btop proc_banner_shown: paused or following. */
    private function banner(Config $config): bool
    {
        return $config->bool('pause_proc_list') || $this->followedPid !== null;
    }

    /**
     * btop select_max: the list rows, minus the detailed view's 8 when it
     * is open and the banner's row when it shows.
     */
    private function selectMax(Config $config, PanelContext $context): int
    {
        return max(0, $context->procSelectMax() - ProcView::detailRows($this->detailDrawn($config, $context)) - ($this->banner($config) ? 1 : 0));
    }

    /**
     * Whether the detailed view is open AND painted — the painter drops it
     * from a box too small for its 8 rows ({@see ProcView::detailFits()}),
     * and selection, scrolling and mouse rows must then use the full list.
     */
    private function detailDrawn(Config $config, PanelContext $context): bool
    {
        $box = $context->box;

        return $config->bool('show_detailed') && $this->detail !== null
            && ($box === null || ProcView::detailFits($box->width, $box->height));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mutate(array $props): self
    {
        $clone = clone $this;
        foreach ($props as $name => $value) {
            $clone->{$name} = $value;
        }

        return $clone;
    }
}
