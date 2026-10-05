<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * The `/sessions` (Ctrl+R) list dialog: browse, search and manage sessions
 * (Appendix P §3.2).
 *
 * What it shows:
 * - one row per session — pin star, title, how long ago it was used, turn
 *   count, provider/model and a status badge (`⠋ live`, `⧗ bg`, `+N ag`,
 *   `⑂`), the right-hand columns dropped one by one as the box narrows;
 * - rows grouped Pinned, Today, Yesterday, then by date, with archived rows
 *   in a group of their own once `a` shows them;
 * - sub-agent sessions indented under their parent once Tab shows them;
 * - a footer naming where the highlighted session was opened, its git branch
 *   and the last prompt sent in it.
 *
 * What it does, beyond ↑↓ / Enter / Esc:
 * - `/` filters: typed text goes to the query, which candy-fuzzy's
 *   Smith-Waterman matcher ranks over title, last prompt, agent, id and
 *   branch, with the matched characters highlighted. `/` (rather than
 *   type-to-filter) is what keeps `k`/`j` moving the highlight.
 * - `r` renames inline, `d` deletes after a second press, `p` pins, `f` forks,
 *   `x` archives, `u` unarchives, `a` shows archived rows, Tab shows children.
 *   Ctrl+E / Ctrl+D / Ctrl+F reach rename, delete and pin from inside the
 *   filter, where the letters are text.
 *
 * The picker only holds display state. A key that should change the store or
 * the current session comes back as a {@see SessionListAction} for the host
 * ({@see \SugarCraft\Crush\Chat}) to carry out, which then hands fresh rows
 * back through {@see withReloadedRows()}.
 *
 * {@see ItemList} is the selection model (E744 WS5): cursor, end clamping, the
 * mouse hit-map and the load-more edge belong to it. The pixels stay here —
 * this overlay paints its own chrome and a center-on-cursor scroll window, so
 * `ItemList::view()` is never called. The list is built focused, its own
 * surfaces off and one row per loaded session tall, which keeps its pan
 * offset at zero: the one configuration where a click on row N is item N
 * (see {@see updateClick()}). Browsing clamps at both ends rather than
 * wrapping, because arriving on the last row while more exist upstream is the
 * signal to fetch the next page.
 *
 * Mirrors charmbracelet/crush's session picker, extended after opencode's
 * session list (grouping, search, inline rename, delete confirm).
 *
 * @phpstan-type Row array{
 *     sessionId: string,
 *     sessionName: string,
 *     summary: string,
 *     gitBranch: string|null,
 *     lastActivity: string,
 *     cwd?: string|null,
 *     title?: string|null,
 *     pinned?: bool,
 *     archived?: bool,
 *     kind?: string,
 *     turns?: int,
 *     provider?: string,
 *     model?: string,
 *     status?: string|null,
 *     parentId?: string|null,
 *     agent?: string|null,
 *     subagents?: int,
 *     children?: int,
 *     live?: bool
 * }
 */
final class SessionPicker
{
    /**
     * Store fetch limit for a freshly opened picker and the step a
     * {@see LoadMoreMsg} widens it by. A fetch that FILLS its limit leaves
     * {@see needsStoreFetch()} true; a short fetch closes the edge.
     */
    public const PAGE_SIZE = 20;

    /**
     * Rows loaded once when the filter opens. Paging is suspended while a
     * query is active (opencode does the same with its search limit): the
     * ranking runs in PHP over these rows, so no keystroke reads the store.
     */
    public const SEARCH_LIMIT = 200;

    /** The longest title the inline rename accepts, in characters. */
    public const RENAME_MAX = 120;

    /** Transcript lines Space shows in the footer. */
    public const PREVIEW_LINES = 6;

    /** The right-aligned mouse cluster on the highlighted row: verb => glyph. */
    public const ACTION_GLYPHS = ['rename' => '✎', 'pin' => '★', 'delete' => '✕'];

    /** Below this box width the action cluster is not drawn. */
    private const CLUSTER_MIN_WIDTH = 50;

    /** Header lines above the box: title, rule, key hints. */
    private const HEADER_LINES = 3;

    /** Border (2) and footer (rule + detail line, 2) around the body. */
    private const CHROME_LINES = 4;

    /** @var list<Row> every loaded row, children included */
    private readonly array $sessions;

    /** @var list<Row> the selectable rows, in display order */
    private readonly array $visible;

    /**
     * Painted lines in display order: a group heading, or a row by its index
     * into {@see $visible} (with its nesting depth).
     *
     * @var list<array{header: string}|array{row: int, depth: int}>
     */
    private readonly array $entries;

    /**
     * The selection model. Not readonly only so {@see withList()} can swap it
     * on a fresh clone; no instance is ever changed after it is handed out.
     */
    private ItemList $list;

    private static ?SmithWatermanMatcher $matcher = null;

    /**
     * @param list<Row>                                   $sessions
     * @param array{id: string, lines: list<string>}|null $preview
     */
    private function __construct(
        array $sessions,
        private readonly ?string $branchFilter,
        private readonly int $fetchLimit,
        private readonly bool $moreInStore,
        private readonly ?string $currentBranch,
        private readonly ?string $currentSessionId,
        private readonly int $now,
        private readonly string $query,
        private readonly bool $filtering,
        private readonly bool $showChildren,
        private readonly bool $showArchived,
        private readonly ?string $armedDeleteId,
        private readonly ?string $renameId,
        private readonly ?TextInput $renameInput,
        private readonly ?array $preview,
        private readonly ?string $notice,
        int $cursor,
    ) {
        $this->sessions = $sessions;
        [$this->visible, $this->entries] = $this->layout();

        $items = array_map(static fn (array $row): SessionRow => SessionRow::fromSession($row), $this->visible);
        $list = ItemList::new($items, 60, max(1, count($items)))
            ->withShowDescription(false)
            ->withShowFilter(false)
            ->withShowStatusBar(false)
            ->withShowHelp(false)
            // A search has loaded everything it will rank; paging is off.
            ->withHasMore($moreInStore && $query === '');
        [$focused] = $list->focus();
        \assert($focused instanceof ItemList);

        // select() positions without firing the arrival edge that only
        // keyboard/mouse navigation raises.
        $this->list = $focused->select($cursor);
    }

    /**
     * A picker over $sessions, highlighting the first row.
     *
     * @param list<Row> $sessions
     * @param ?string   $currentBranch    the branch the work tree is on, read once by the
     *                                    host when it opens the picker (Ctrl+B filters to it)
     * @param ?string   $currentSessionId the session on screen, which delete and archive refuse
     * @param ?int      $now              the clock relative times and day groups are read against
     */
    public static function new(
        array $sessions,
        int $fetchLimit = self::PAGE_SIZE,
        bool $moreInStore = false,
        ?string $currentBranch = null,
        ?string $currentSessionId = null,
        ?int $now = null,
    ): self {
        return new self(
            $sessions,
            null,
            $fetchLimit,
            $moreInStore,
            $currentBranch,
            $currentSessionId,
            $now ?? time(),
            '',
            false,
            false,
            false,
            null,
            null,
            null,
            null,
            null,
            0,
        );
    }

    /**
     * A copy with $changes applied and the highlight placed on $keepId when
     * that row is still visible, else on row $cursor (clamped).
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes, ?int $cursor = null, ?string $keepId = null): self
    {
        $field = fn (string $name): mixed => array_key_exists($name, $changes) ? $changes[$name] : $this->{$name};

        $next = new self(
            $field('sessions'),
            $field('branchFilter'),
            $field('fetchLimit'),
            $field('moreInStore'),
            $field('currentBranch'),
            $field('currentSessionId'),
            $field('now'),
            $field('query'),
            $field('filtering'),
            $field('showChildren'),
            $field('showArchived'),
            $field('armedDeleteId'),
            $field('renameId'),
            $field('renameInput'),
            $field('preview'),
            $field('notice'),
            $cursor ?? $this->selectedIndex(),
        );

        if ($keepId !== null) {
            foreach ($next->visible as $i => $row) {
                if ($row['sessionId'] === $keepId) {
                    return $i === $next->selectedIndex() ? $next : $next->withList($next->list->select($i));
                }
            }
        }

        return $next;
    }

    /** Swap in a navigated selection model, keeping everything else. */
    private function withList(ItemList $list): self
    {
        // The layout depends on rows and view state, not on the cursor, so
        // a navigation keeps it and swaps only the model.
        $clone = clone $this;
        $clone->list = $list;

        return $clone;
    }

    // ------------------------------------------------------------------
    // Layout: which rows are visible, in what order, under which headings
    // ------------------------------------------------------------------

    /**
     * @return array{0: list<Row>, 1: list<array{header: string}|array{row: int, depth: int}>}
     */
    private function layout(): array
    {
        $children = [];
        $top = [];
        $loaded = [];
        foreach ($this->sessions as $row) {
            $loaded[$row['sessionId']] = true;
        }
        foreach ($this->sessions as $row) {
            if (!$this->showArchived && ($row['archived'] ?? false)) {
                continue;
            }
            $parent = $row['parentId'] ?? null;
            if (($row['kind'] ?? 'main') === 'subagent') {
                if ($this->showChildren && $parent !== null && isset($loaded[$parent])) {
                    $children[$parent][] = $row;
                }

                continue;
            }
            if ($this->branchFilter !== null && ($row['gitBranch'] ?? null) !== $this->branchFilter) {
                continue;
            }
            $top[] = $row;
        }

        if ($this->query !== '') {
            return $this->rankedLayout($top, $children);
        }

        $groups = [];
        foreach ($top as $row) {
            $groups[$this->groupOf($row)][] = $row;
        }
        // Pinned first and Archived last; the day groups between them keep
        // the store's newest-first order of first appearance.
        $order = array_keys($groups);
        usort($order, static fn (string $a, string $b): int => self::groupRank($a) <=> self::groupRank($b));

        $visible = [];
        $entries = [];
        foreach ($order as $label) {
            $entries[] = ['header' => $label];
            foreach ($groups[$label] as $row) {
                $entries[] = ['row' => count($visible), 'depth' => 0];
                $visible[] = $row;
                foreach ($children[$row['sessionId']] ?? [] as $child) {
                    $entries[] = ['row' => count($visible), 'depth' => 1];
                    $visible[] = $child;
                }
            }
        }

        return [$visible, $entries];
    }

    /**
     * A flat list, best match first, no headings — the palette's rule for a
     * typed query, so the best match is always the first row.
     *
     * @param list<Row>                $top
     * @param array<string, list<Row>> $children
     *
     * @return array{0: list<Row>, 1: list<array{row: int, depth: int}>}
     */
    private function rankedLayout(array $top, array $children): array
    {
        $candidates = $top;
        foreach ($children as $rows) {
            array_push($candidates, ...$rows);
        }

        $scored = [];
        foreach ($candidates as $i => $row) {
            $match = self::matcher()->match($this->query, self::haystack($row));
            if ($match !== null && $match->score > 0) {
                $scored[] = [$match->score, $i, $row];
            }
        }
        // Score first, then the store's order, so equal scores stay newest first.
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $visible = array_map(static fn (array $s): array => $s[2], $scored);
        $entries = [];
        foreach ($visible as $i => $row) {
            $entries[] = ['row' => $i, 'depth' => ($row['kind'] ?? 'main') === 'subagent' ? 1 : 0];
        }

        return [$visible, $entries];
    }

    /** What the filter searches: title, last prompt, agent, id and branch. */
    private static function haystack(array $row): string
    {
        return implode(' ', array_filter([
            $row['title'] ?? $row['sessionName'],
            $row['summary'] ?? '',
            $row['agent'] ?? '',
            $row['sessionId'],
            $row['gitBranch'] ?? '',
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    private static function matcher(): SmithWatermanMatcher
    {
        return self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);
    }

    /** The group heading a top-level row sorts under. */
    private function groupOf(array $row): string
    {
        if ($row['archived'] ?? false) {
            return Lang::t('tui.picker.group.archived');
        }
        if ($row['pinned'] ?? false) {
            return Lang::t('tui.picker.group.pinned');
        }
        $at = self::timestamp($row['lastActivity'] ?? '');
        if ($at === null) {
            return Lang::t('tui.picker.group.earlier');
        }
        $day = date('Y-m-d', $at);
        if ($day === date('Y-m-d', $this->now)) {
            return Lang::t('tui.picker.group.today');
        }
        if ($day === date('Y-m-d', $this->now - 86400)) {
            return Lang::t('tui.picker.group.yesterday');
        }

        return date('j M Y', $at);
    }

    private static function groupRank(string $label): int
    {
        // The headings arrive translated (groupOf()), so they are compared
        // translated: the rank is the same in every locale.
        return match ($label) {
            Lang::t('tui.picker.group.pinned') => 0,
            Lang::t('tui.picker.group.earlier') => 2,
            Lang::t('tui.picker.group.archived') => 3,
            default => 1,
        };
    }

    /** A stored timestamp (SQLite's UTC `Y-m-d H:i:s`, or ISO 8601) as a Unix time. */
    private static function timestamp(string $stored): ?int
    {
        if ($stored === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** `now`, `2m`, `3h`, `4d`, then `12 Sep` (this year) or `Dec'25` — six cells at most. */
    private function relative(string $stored): string
    {
        $at = self::timestamp($stored);
        if ($at === null) {
            return '';
        }
        $age = max(0, $this->now - $at);

        return match (true) {
            $age < 60 => Lang::t('tui.picker.age.now'),
            $age < 3600 => Lang::t('tui.picker.age.minutes', ['count' => intdiv($age, 60)]),
            $age < 86400 => Lang::t('tui.picker.age.hours', ['count' => intdiv($age, 3600)]),
            $age < 7 * 86400 => Lang::t('tui.picker.age.days', ['count' => intdiv($age, 86400)]),
            date('Y', $at) === date('Y', $this->now) => date('j M', $at),
            default => date("M'y", $at),
        };
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /**
     * The selectable rows, in the order they are painted.
     *
     * @return list<Row>
     */
    public function filteredSessions(): array
    {
        return $this->visible;
    }

    /**
     * Every loaded row, hidden ones included.
     *
     * @return list<Row>
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * The highlighted row, or null when nothing is shown.
     *
     * @return Row|null
     */
    public function selectedSession(): array|null
    {
        return $this->visible[$this->selectedIndex()] ?? null;
    }

    /** The highlighted index into {@see filteredSessions()} — the ItemList cursor. */
    public function selectedIndex(): int
    {
        return $this->list->index();
    }

    public function branchFilter(): string|null
    {
        return $this->branchFilter;
    }

    /** The branch Ctrl+B filters to: the work tree's, as read when the picker opened. */
    public function currentBranch(): ?string
    {
        return $this->currentBranch;
    }

    /** The filter text; '' when there is none. */
    public function query(): string
    {
        return $this->query;
    }

    /** Is the `/` filter taking keystrokes? */
    public function isFiltering(): bool
    {
        return $this->filtering;
    }

    public function showsChildren(): bool
    {
        return $this->showChildren;
    }

    public function showsArchived(): bool
    {
        return $this->showArchived;
    }

    /** The session a first `d` armed for deletion, or null. */
    public function armedDeleteId(): ?string
    {
        return $this->armedDeleteId;
    }

    public function isRenaming(): bool
    {
        return $this->renameId !== null;
    }

    /**
     * The inline rename being edited: which session, and the title typed so
     * far (trimmed). Read by the host when the picker reports
     * {@see SessionListAction::Rename}.
     *
     * @return array{id: string, title: string}|null
     */
    public function renameTarget(): ?array
    {
        if ($this->renameId === null || $this->renameInput === null) {
            return null;
        }

        return ['id' => $this->renameId, 'title' => trim($this->renameInput->value)];
    }

    /**
     * The transcript excerpt Space loaded, for the row it belongs to.
     *
     * @return array{id: string, lines: list<string>}|null
     */
    public function preview(): ?array
    {
        return $this->preview;
    }

    /** A one-line message in the footer (a refused action), or null. */
    public function notice(): ?string
    {
        return $this->notice;
    }

    public function needsStoreFetch(): bool
    {
        return $this->moreInStore && $this->query === '';
    }

    public function fetchLimit(): int
    {
        return $this->fetchLimit;
    }

    public function nextFetchLimit(): int
    {
        return $this->fetchLimit + self::PAGE_SIZE;
    }

    public function isEmpty(): bool
    {
        return $this->visible === [];
    }

    public function count(): int
    {
        return count($this->visible);
    }

    // ------------------------------------------------------------------
    // Withers
    // ------------------------------------------------------------------

    public function withSelectedIndex(int $index): self
    {
        return $this->withList($this->list->select($index));
    }

    /** Filter to sessions opened on $branch (null shows every branch); highlights the first row. */
    public function withBranchFilter(string|null $branch): self
    {
        return $this->mutate(['branchFilter' => $branch, 'armedDeleteId' => null, 'preview' => null], 0);
    }

    /**
     * Replace the rows (a fresh read of the store); highlights the first row.
     *
     * @param list<Row> $sessions
     */
    public function withSessions(array $sessions): self
    {
        return $this->mutate(['sessions' => $sessions, 'armedDeleteId' => null, 'preview' => null], 0);
    }

    /**
     * Grow the rows after a load-more fetch, keeping the cursor where it is:
     * the user is standing on the last row of the previous page and the point
     * is to keep walking. The clamp comes from `ItemList::select()`, which
     * cannot fire the edge — growth is never a navigation.
     *
     * @param list<Row> $sessions
     */
    public function withFetchedRows(array $sessions, int $fetchLimit, bool $moreInStore): self
    {
        return $this->mutate(['sessions' => $sessions, 'fetchLimit' => $fetchLimit, 'moreInStore' => $moreInStore]);
    }

    /**
     * Replace the rows after an action changed the store, keeping the
     * highlight on $keepId when it is still listed (a pin moves the row to
     * another group; the highlight follows it).
     *
     * @param list<Row> $sessions
     */
    public function withReloadedRows(array $sessions, int $fetchLimit, bool $moreInStore, ?string $keepId = null): self
    {
        return $this->mutate([
            'sessions' => $sessions,
            'fetchLimit' => $fetchLimit,
            'moreInStore' => $moreInStore,
            'armedDeleteId' => null,
            'renameId' => null,
            'renameInput' => null,
            'preview' => null,
        ], null, $keepId ?? $this->selectedSession()['sessionId'] ?? null);
    }

    /** Open the filter on $query, best match highlighted. */
    public function withQuery(string $query): self
    {
        return $this->mutate(['query' => $query, 'filtering' => true, 'armedDeleteId' => null, 'preview' => null], 0);
    }

    public function withShowChildren(bool $show = true): self
    {
        return $this->mutate(['showChildren' => $show, 'armedDeleteId' => null, 'preview' => null], null, $this->selectedSession()['sessionId'] ?? null);
    }

    public function withShowArchived(bool $show = true): self
    {
        return $this->mutate(['showArchived' => $show, 'armedDeleteId' => null, 'preview' => null], null, $this->selectedSession()['sessionId'] ?? null);
    }

    /**
     * Show $lines (already sanitized by the host) as the footer preview of
     * session $id. Kept only while that row stays highlighted.
     *
     * @param list<string> $lines
     */
    public function withPreview(string $id, array $lines): self
    {
        return $this->mutate(['preview' => ['id' => $id, 'lines' => array_slice($lines, -self::PREVIEW_LINES)]]);
    }

    public function withNotice(?string $notice): self
    {
        return $this->mutate(['notice' => $notice]);
    }

    public function withNow(int $now): self
    {
        return $this->mutate(['now' => $now]);
    }

    // ------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------

    /**
     * Handle one keystroke.
     *
     * The tuple's middle slot names what happened: `'browse'` (the highlight
     * or a view toggle moved), `'edit'` (filter or rename text changed),
     * `'resume'` (Enter on a row), `'preview'` (Space), `'close'` (Esc), a
     * {@see SessionListAction} for the host to carry out, or null for a key
     * with no binding. The third slot is the Cmd an {@see ItemList}
     * navigation may raise (the load-more edge).
     *
     * A string names a key the way the tests and older callers spell it
     * (`'up'`, `'enter'`, `'ctrl+b'`, `'r'`, …); a {@see KeyMsg} is what the
     * host passes, and is needed for text typed into the filter or rename.
     *
     * @return array{0: self, 1: string|SessionListAction|null, 2: ?\Closure}
     */
    public function handleKey(string|KeyMsg $key): array
    {
        $msg = $key instanceof KeyMsg ? $key : self::keyMsgFor($key);
        $name = $key instanceof KeyMsg ? self::keyName($key) : $key;
        // Any keystroke retires the last refusal notice.
        $self = $this->notice === null ? $this : $this->mutate(['notice' => null]);

        if ($self->renameId !== null) {
            return $self->renameKey($name, $msg);
        }
        if ($self->filtering) {
            return $self->filterKey($name, $msg);
        }

        return $self->rowKey($name);
    }

    /** Keys while the inline rename row is open. */
    private function renameKey(string $name, ?KeyMsg $msg): array
    {
        if ($name === 'enter') {
            return [$this, SessionListAction::Rename, null];
        }
        if ($name === 'escape') {
            return [$this->mutate(['renameId' => null, 'renameInput' => null]), 'browse', null];
        }
        if ($msg === null || $this->renameInput === null) {
            return [$this, null, null];
        }
        [$input] = $this->renameInput->update($msg);
        \assert($input instanceof TextInput);

        return [$this->mutate(['renameInput' => $input]), 'edit', null];
    }

    /** Keys while the `/` filter is open: text goes to the query. */
    private function filterKey(string $name, ?KeyMsg $msg): array
    {
        switch ($name) {
            case 'up':
            case 'down':
            case 'enter':
            case 'ctrl+b':
            case 'tab':
                return $this->rowKey($name);
            case 'ctrl+e':
                return $this->rowKey('r');
            case 'ctrl+d':
                return $this->rowKey('d');
            case 'ctrl+f':
                return $this->rowKey('p');
            case 'escape':
                // Esc clears the filter; a second Esc (outside it) closes.
                return [$this->mutate(['query' => '', 'filtering' => false, 'armedDeleteId' => null], 0), 'edit', null];
            case 'backspace':
                if ($this->query === '') {
                    return [$this->mutate(['filtering' => false]), 'edit', null];
                }

                return [$this->withQuery(mb_substr($this->query, 0, -1, 'UTF-8')), 'edit', null];
        }

        $typed = $msg === null ? '' : match (true) {
            $msg->type === KeyType::Space => ' ',
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt => $msg->rune,
            default => '',
        };
        if ($typed === '' || mb_strlen($this->query . $typed, 'UTF-8') > self::RENAME_MAX) {
            return [$this, null, null];
        }

        return [$this->withQuery($this->query . $typed), 'edit', null];
    }

    /** Keys on the list itself. */
    private function rowKey(string $name): array
    {
        $selected = $this->selectedSession();
        $armed = $this->armedDeleteId;
        // A delete is armed by one `d` and confirmed by the very next key;
        // anything else stands it down.
        $self = $armed !== null && $name !== 'd' && $name !== 'D' && $name !== 'ctrl+d'
            ? $this->mutate(['armedDeleteId' => null])
            : $this;
        if ($armed !== null && $name === 'escape') {
            return [$self, 'browse', null];
        }

        return match ($name) {
            'up', 'k' => $self->browsing(new KeyMsg(KeyType::Up)),
            'down', 'j' => $self->browsing(new KeyMsg(KeyType::Down)),
            'enter' => [$self, $selected !== null ? 'resume' : null, null],
            ' ' => [$self, $selected !== null ? 'preview' : null, null],
            'escape' => [$self, 'close', null],
            'ctrl+b' => $self->currentBranch === null && $self->branchFilter === null
                ? [$self->mutate(['notice' => Lang::t('tui.picker.notice.no_branch')]), 'browse', null]
                : [$self->withBranchFilter($self->branchFilter === null ? $self->currentBranch : null), 'browse', null],
            '/' => [$self->mutate(['filtering' => true, 'preview' => null]), SessionListAction::Filter, null],
            'tab' => [$self->withShowChildren(!$self->showChildren), SessionListAction::ToggleChildren, null],
            'a' => [$self->withShowArchived(!$self->showArchived), SessionListAction::ToggleArchived, null],
            'r', 'ctrl+e' => $self->startRename($selected),
            'd', 'D', 'ctrl+d' => $self->deleteKey($selected, $name === 'D'),
            'p', 'ctrl+f' => [$self, $selected !== null ? SessionListAction::Pin : null, null],
            'f' => [$self, $selected !== null ? SessionListAction::Fork : null, null],
            'x' => $self->archiveKey($selected),
            'u' => [$self, $selected !== null && ($selected['archived'] ?? false) ? SessionListAction::Unarchive : null, null],
            default => [$self, null, null],
        };
    }

    /** @param Row|null $selected */
    private function startRename(?array $selected): array
    {
        if ($selected === null) {
            return [$this, null, null];
        }
        $input = TextInput::new()
            ->withPrompt('')
            ->withCharLimit(self::RENAME_MAX)
            ->setValue((string) ($selected['title'] ?? ''));
        // The blink Cmd is dropped: the row is repainted on every keystroke,
        // and a steady cursor reads fine in a one-line editor.
        [$input] = $input->focus();
        \assert($input instanceof TextInput);

        return [$this->mutate(['renameId' => $selected['sessionId'], 'renameInput' => $input->cursorEnd(), 'preview' => null]), 'edit', null];
    }

    /**
     * `d` arms the highlighted row, and the next `d` reports
     * {@see SessionListAction::Delete}; `D` on an armed row reports
     * {@see SessionListAction::DeleteWithChildren}. The session on screen and
     * a running background session are refused here, before anything is armed.
     *
     * @param Row|null $selected
     */
    private function deleteKey(?array $selected, bool $withChildren): array
    {
        if ($selected === null) {
            return [$this, null, null];
        }
        if ($this->armedDeleteId === $selected['sessionId']) {
            return [$this, $withChildren ? SessionListAction::DeleteWithChildren : SessionListAction::Delete, null];
        }
        if ($withChildren) {
            return [$this, null, null];
        }
        if ($selected['sessionId'] === $this->currentSessionId) {
            return [$this->mutate(['notice' => Lang::t('tui.picker.notice.delete_current')]), 'browse', null];
        }
        if (($selected['status'] ?? null) === 'running' || ($selected['live'] ?? false)) {
            return [$this->mutate(['notice' => Lang::t('tui.picker.notice.delete_running')]), 'browse', null];
        }

        return [$this->mutate(['armedDeleteId' => $selected['sessionId'], 'preview' => null]), 'browse', null];
    }

    /** @param Row|null $selected */
    private function archiveKey(?array $selected): array
    {
        if ($selected === null || ($selected['archived'] ?? false)) {
            return [$this, null, null];
        }
        if ($selected['sessionId'] === $this->currentSessionId) {
            return [$this->mutate(['notice' => Lang::t('tui.picker.notice.archive_current')]), 'browse', null];
        }

        return [$this, SessionListAction::Archive, null];
    }

    /**
     * Forward one navigation into the ItemList and wrap its answer as a
     * browse. A preview belongs to the row it was opened on, so moving off
     * that row drops it.
     */
    private function browsing(KeyMsg $key): array
    {
        [$next, $cmd] = $this->list->update($key);
        \assert($next instanceof ItemList);
        $moved = $this->withList($next);
        if ($moved->preview !== null && $moved->preview['id'] !== ($moved->selectedSession()['sessionId'] ?? null)) {
            $moved = $moved->mutate(['preview' => null]);
        }

        return [$moved, 'browse', $cmd];
    }

    /** The name {@see handleKey()} matches a {@see KeyMsg} by. */
    public static function keyName(KeyMsg $msg): string
    {
        return match (true) {
            $msg->type === KeyType::Up => 'up',
            $msg->type === KeyType::Down => 'down',
            $msg->type === KeyType::Enter => 'enter',
            $msg->type === KeyType::Space => ' ',
            $msg->type === KeyType::Escape => 'escape',
            $msg->type === KeyType::Backspace => 'backspace',
            $msg->type === KeyType::Tab && !$msg->ctrl && !$msg->alt && !$msg->shift => 'tab',
            $msg->type === KeyType::Char && $msg->ctrl && !$msg->alt => 'ctrl+' . strtolower($msg->rune),
            // Plain letters only: Alt+<letter> is a terminal chord, not a row key.
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt => $msg->rune,
            default => '',
        };
    }

    /** The {@see KeyMsg} a string key name stands for, so text input works from either form. */
    private static function keyMsgFor(string $name): ?KeyMsg
    {
        return match (true) {
            $name === 'up' => new KeyMsg(KeyType::Up),
            $name === 'down' => new KeyMsg(KeyType::Down),
            $name === 'enter' => new KeyMsg(KeyType::Enter),
            $name === ' ' => new KeyMsg(KeyType::Space, ' '),
            $name === 'escape' => new KeyMsg(KeyType::Escape),
            $name === 'backspace' => new KeyMsg(KeyType::Backspace),
            $name === 'tab' => new KeyMsg(KeyType::Tab),
            str_starts_with($name, 'ctrl+') && mb_strlen($name) === 6 => new KeyMsg(KeyType::Char, substr($name, 5), ctrl: true),
            mb_strlen($name, 'UTF-8') === 1 => new KeyMsg(KeyType::Char, $name),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Mouse
    // ------------------------------------------------------------------

    /**
     * Select the row painted at filtered index $row by a click (E744 WS4) —
     * forwarded as a one-based list line into `ItemList::handleMouse()`,
     * which is exact because the model's pan offset never leaves zero.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function updateClick(int $row): array
    {
        if ($row < 0 || $row >= count($this->visible)) {
            return [$this, null];
        }

        [$next, $cmd] = $this->list->update(new MouseClickMsg(1, $row + 1, MouseButton::Left, MouseAction::Press));
        \assert($next instanceof ItemList);
        if ($next->index() === $this->selectedIndex()) {
            return [$this->withList($next), $cmd];
        }

        return [$this->withList($next)->mutate(['armedDeleteId' => null, 'preview' => null]), $cmd];
    }

    /**
     * Wheel the selection by one row (E744 WS4), through the widget's own
     * wheel arm and its load-more edge.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function updateWheel(MouseButton $direction): array
    {
        [$next, $cmd] = $this->list->update(new MouseWheelMsg(1, 1, $direction, MouseAction::Press));
        \assert($next instanceof ItemList);
        if ($next->index() === $this->selectedIndex()) {
            return [$this->withList($next), $cmd];
        }

        // Moving off a row stands down its armed delete and drops its preview.
        return [$this->withList($next)->mutate(['armedDeleteId' => null, 'preview' => null]), $cmd];
    }

    // ------------------------------------------------------------------
    // Painting
    // ------------------------------------------------------------------

    /**
     * The picker's overlay box for a terminal of $cols x $rows.
     *
     * Single source for BOTH the painting pass ({@see \SugarCraft\Crush\Renderer::renderSessionPicker()})
     * and the click-zone validation pass ({@see \SugarCraft\Crush\Chat}'s
     * picker arm) — the geometry a click is judged against must be the
     * geometry the rows were painted at.
     *
     * @return array{0: int, 1: int} [width, height]
     */
    public static function overlayGeometry(int $cols, int $rows, int $shellChromeCols): array
    {
        $inner = max(20, $cols - $shellChromeCols);

        return [max(20, min($inner - 4, 76)), max(8, $rows - 4)];
    }

    /**
     * The body lines the CURRENT paint shows, keyed by absolute filtered row,
     * for the click-zone registry (E744 WS4). Derived from the same
     * {@see window()} the overlay is painted from; group headings and the
     * `(no sessions)` line are not rows, so they are never keyed.
     *
     * @return array<int, string>
     */
    public function rowZoneLines(int $width, int $height, Theme $theme): array
    {
        $lines = [];
        foreach ($this->window($height) as $entry) {
            if (isset($entry['row'])) {
                $lines[$entry['row']] = $this->renderRow($entry['row'], $entry['depth'], $width, $theme);
            }
        }

        return $lines;
    }

    /**
     * The action glyphs painted on the highlighted row, as they appear in
     * that row's line: verb => the styled glyph. The renderer wraps each in a
     * `session-act:<row>:<verb>` zone; empty when the box is too narrow to
     * draw the cluster or the row is in a state with no actions.
     *
     * @return array<string, string>
     */
    public function actionSegments(int $width, Theme $theme): array
    {
        $selected = $this->selectedSession();
        if ($width < self::CLUSTER_MIN_WIDTH || $selected === null || $this->renameId !== null) {
            return [];
        }

        $segments = [];
        foreach (self::ACTION_GLYPHS as $verb => $glyph) {
            // An armed row keeps only its `✕`: the second click confirms.
            if ($this->armedDeleteId !== null && $verb !== 'delete') {
                continue;
            }
            $segments[$verb] = Style::new()->foreground($verb === 'delete' ? $theme->shellError : $theme->shellPrimary)->render($glyph);
        }

        return $segments;
    }

    /**
     * The painted window: the entries shown in a box of $height rows, chosen
     * so the highlighted row stays visible.
     *
     * @return list<array{header: string}|array{row: int, depth: int}>
     */
    private function window(int $height): array
    {
        $available = max(0, $height - self::HEADER_LINES - self::CHROME_LINES - $this->footerExtraLines());
        $total = count($this->entries);
        if ($total <= $available) {
            return $this->entries;
        }

        $line = 0;
        foreach ($this->entries as $i => $entry) {
            if (($entry['row'] ?? -1) === $this->selectedIndex()) {
                $line = $i;
                break;
            }
        }
        $start = 0;
        if ($line >= $available) {
            $start = max(0, min($total - $available, $line - intdiv($available, 2)));
        }

        return array_slice($this->entries, $start, $available);
    }

    /** Footer lines beyond the fixed rule + detail line: the preview. */
    private function footerExtraLines(): int
    {
        return $this->preview === null ? 0 : count($this->preview['lines']);
    }

    public function render(int $width, int $height, Theme $theme): string
    {
        $lines = [];
        foreach ($this->window($height) as $entry) {
            $lines[] = isset($entry['header'])
                ? Style::new()->foreground($theme->shellMuted)->bold()->render(self::fit($entry['header'], $width))
                : $this->renderRow($entry['row'], $entry['depth'], $width, $theme);
        }
        if ($lines === []) {
            $lines[] = Style::new()
                ->foreground($theme->shellMuted)
                ->render('  ' . Lang::t($this->query !== '' ? 'tui.picker.empty_match' : 'tui.picker.empty'));
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.picker.box_title') . ' '))
            ->borderForeground($theme->shellPrimary)
            ->padding(0, 1)
            ->width($width);
        if ($this->visible === [] && ($this->branchFilter !== null || $this->query !== '')) {
            $st = $st->borderForeground($theme->shellWarning);
        }

        return $this->renderHeader($width, $theme) . "\n" . $st->render(implode("\n", $lines)) . $this->renderFooter($width, $theme);
    }

    /** Title (count, filter, branch), a rule, and the key hints for the current mode. */
    private function renderHeader(int $width, Theme $theme): string
    {
        $title = ' ' . Lang::t('tui.picker.title', ['count' => count($this->visible)]);
        if ($this->showArchived) {
            $title .= ' · ' . Lang::t('tui.picker.title_archived');
        }
        if ($this->branchFilter !== null) {
            $title .= ' · ' . Lang::t('tui.picker.title_branch', ['branch' => $this->branchFilter]);
        }
        if ($this->filtering || $this->query !== '') {
            $title .= ' · / ' . $this->query . ($this->filtering ? '▏' : '');
        }

        $hints = match (true) {
            $this->renameId !== null => ' ' . Lang::t('tui.picker.hints.rename'),
            $this->armedDeleteId !== null => ' ' . Lang::t('tui.picker.hints.armed_delete'),
            $this->filtering => ' ' . Lang::t('tui.picker.hints.filtering'),
            // Esc first so a narrow box keeps the way out.
            default => ' ' . Lang::t('tui.picker.hints.browse'),
        };

        // Themed, like its twin in renderFooter(): an unstyled rule would be
        // the terminal's default colour, which this shell does not choose.
        $separator = Style::new()
            ->foreground($theme->border)
            ->render(str_repeat('─', max(0, $width - 2)));

        return Style::new()->foreground($theme->shellPrimary)->bold()->render(self::clip($title, $width))
            . "\n" . $separator . "\n"
            . Style::new()->foreground($theme->shellMuted)->render(self::clip($hints, $width));
    }

    /**
     * One row, fitted to exactly $width cells so the box never wraps it.
     *
     * Columns left to right: highlight marker, pin star, title (flexible),
     * relative time (≥44 cells), turns (≥56), provider/model (≥68), status
     * badge (≥60) and the mouse cluster (≥50, highlighted row only, space
     * reserved on every row so the columns line up).
     */
    private function renderRow(int $index, int $depth, int $width, Theme $theme): string
    {
        $row = $this->visible[$index];
        $selected = $index === $this->selectedIndex();
        $marker = ($selected ? '▶' : ' ') . ' ';
        $muted = Style::new()->foreground($theme->shellMuted);

        if ($this->armedDeleteId === $row['sessionId']) {
            $children = (int) ($row['children'] ?? 0);
            $subagents = (int) ($row['subagents'] ?? 0);
            $text = $marker . Lang::t('tui.picker.confirm_delete', ['name' => Width::truncate($row['sessionName'], 24)]);
            if ($subagents > 0) {
                $text .= ' ' . Lang::t('tui.picker.confirm_delete_subagents', ['count' => $subagents]);
            }
            if ($children - $subagents > 0) {
                $text .= ' · ' . Lang::t($children - $subagents === 1 ? 'tui.picker.confirm_delete_branch' : 'tui.picker.confirm_delete_branches', ['count' => $children - $subagents]);
            }

            $cluster = $selected ? $this->actionSegments($width, $theme) : [];
            if ($cluster === []) {
                return Style::new()->foreground($theme->shellError)->bold()->render(self::fit($text, $width));
            }

            return Style::new()->foreground($theme->shellError)->bold()->render(self::fit($text, $width - 6))
                . '     ' . $cluster['delete'];
        }

        if ($this->renameId === $row['sessionId'] && $this->renameInput !== null) {
            $label = $marker . Lang::t('tui.picker.rename_label') . ' ';
            $room = max(1, $width - Width::string($label));
            // Width counts characters; a wide title can still overrun, so the
            // painted run is cut by cells as well.
            $input = Width::truncateAnsi($this->renameInput->withWidth(max(1, $room - 1))->view(), $room);

            return Style::new()->foreground($theme->shellPrimary)->render($label) . $input
                . str_repeat(' ', max(0, $room - Width::string($input)));
        }

        $cluster = $width >= self::CLUSTER_MIN_WIDTH ? 6 : 0;
        $pin = ($row['pinned'] ?? false) ? '★ ' : '  ';
        $columns = [];
        if ($width >= 44) {
            $columns[] = [6, $this->relative($row['lastActivity'] ?? ''), true];
        }
        if ($width >= 56) {
            $turns = (int) ($row['turns'] ?? 0);
            $columns[] = [9, Lang::t($turns === 1 ? 'tui.picker.turn' : 'tui.picker.turns', ['count' => $turns]), true];
        }
        if ($width >= 68) {
            $model = trim(($row['provider'] ?? '') . '/' . ($row['model'] ?? ''), '/');
            $columns[] = [16, Width::truncateMiddle($model, 16), false];
        }
        if ($width >= 60) {
            $columns[] = [6, self::badge($row), false];
        }

        $fixed = Width::string($marker) + Width::string($pin) + $cluster;
        foreach ($columns as [$w]) {
            $fixed += $w + 1;
        }
        $titleWidth = max(4, $width - $fixed);

        $title = $row['sessionName'];
        if ($depth > 0 || ($row['kind'] ?? 'main') === 'subagent') {
            $agent = (string) ($row['agent'] ?? '');
            $title = '└ ' . ($agent !== '' ? $agent . ' · ' : '') . $title . self::statusGlyph($row['status'] ?? null);
        }

        $titleStyle = Style::new()->foreground(match (true) {
            $row['archived'] ?? false => $theme->shellMuted,
            $selected => $theme->shellPrimary,
            default => $theme->shellForeground,
        });
        $line = $marker
            . Style::new()->foreground($theme->shellWarning)->render($pin)
            . $this->highlightTitle(self::fit($title, $titleWidth), $titleStyle, $theme);
        foreach ($columns as [$w, $text, $right]) {
            $text = Width::string($text) > $w ? Width::truncate($text, $w) : $text;
            $line .= ' ' . $muted->render($right ? Width::padLeft($text, $w) : Width::padRight($text, $w));
        }
        if ($cluster > 0) {
            $segments = $selected ? $this->actionSegments($width, $theme) : [];
            $line .= $segments === [] ? str_repeat(' ', $cluster) : ' ' . implode(' ', $segments);
        }

        return $line;
    }

    /**
     * $title (already fitted) in $style, with the characters the filter
     * matched picked out through candy-fuzzy's {@see Highlighter}. The match
     * is re-run on the fitted text so the indices name what is painted.
     */
    private function highlightTitle(string $title, Style $style, Theme $theme): string
    {
        $match = $this->query === '' ? null : self::matcher()->match($this->query, rtrim($title));
        if ($match === null || !$match->isMatched()) {
            return $style->render($title);
        }

        // Highlighter wraps each matched run; NUL brackets mark the runs so
        // each side can be styled on its own (a nested style's reset would
        // otherwise strip the row colour off the rest). Titles are sanitized
        // upstream, so a NUL cannot occur in one.
        $marked = (new Highlighter())->highlight($match, static fn (string $run): string => "\0" . $run . "\0");
        $hit = Style::new()->foreground($theme->shellWarning)->bold()->underline();
        $out = '';
        foreach (explode("\0", $marked . substr($title, strlen(rtrim($title)))) as $i => $part) {
            if ($part !== '') {
                $out .= $i % 2 === 1 ? $hit->render($part) : $style->render($part);
            }
        }

        return $out;
    }

    /** The one-glyph state of a row: running, background, sub-agent children, branch. */
    private static function badge(array $row): string
    {
        return match (true) {
            ($row['live'] ?? false) || ($row['status'] ?? null) === 'running' => '⠋ ' . Lang::t('tui.picker.badge.live'),
            ($row['kind'] ?? 'main') === 'background' => '⧗ ' . Lang::t('tui.picker.badge.background'),
            (int) ($row['subagents'] ?? 0) > 0 => Lang::t('tui.picker.badge.subagents', ['count' => (int) $row['subagents']]),
            ($row['kind'] ?? 'main') === 'branch' => '⑂',
            default => '',
        };
    }

    private static function statusGlyph(?string $status): string
    {
        return match ($status) {
            'complete' => ' ✓',
            'failed' => ' ✗',
            'cancelled', 'interrupted' => ' ⊘',
            'running' => ' ⠋',
            default => '',
        };
    }

    /**
     * The rule, the preview (when Space loaded one), and the highlighted
     * session's `cwd · branch · "last prompt"` — or a refusal notice in its
     * place. Every field arrives sanitized from Chat, and the line is fitted
     * by display width, never bytes, so a multi-byte prompt is never cut
     * mid-character (audit B3).
     */
    private function renderFooter(int $width, Theme $theme): string
    {
        $session = $this->selectedSession();
        if ($session === null && $this->notice === null) {
            return '';
        }

        $maxWidth = max(1, $width - 10);
        $footer = "\n" . Style::new()
            ->foreground($theme->border)
            ->render('─' . str_repeat('─', max(0, $width - 2)));

        if ($this->preview !== null) {
            foreach ($this->preview['lines'] as $line) {
                $footer .= "\n" . Style::new()->foreground($theme->shellMuted)->render('  ' . self::clip($line, $maxWidth));
            }
        }

        if ($this->notice !== null) {
            return $footer . "\n" . Style::new()->foreground($theme->shellWarning)->render('  ' . self::clip($this->notice, $maxWidth));
        }

        $parts = [];
        $cwd = $session['cwd'] ?? null;
        if (\is_string($cwd) && $cwd !== '') {
            $home = getenv('HOME');
            if (\is_string($home) && $home !== '' && $home !== '/' && ($cwd === $home || str_starts_with($cwd, rtrim($home, '/') . '/'))) {
                $cwd = '~' . substr($cwd, \strlen(rtrim($home, '/')));
            }
            // Both ends of a path carry meaning; elide the middle, and never
            // let the path take more than a third of the line.
            $parts[] = Width::truncateMiddle($cwd, max(8, intdiv($maxWidth, 3)));
        }
        $branch = $session['gitBranch'] ?? null;
        if (\is_string($branch) && $branch !== '') {
            $parts[] = $branch;
        }
        $preview = $session['summary'] ?? '';
        $parts[] = $preview !== '' ? '"' . $preview . '"' : Lang::t('tui.picker.no_prompt');

        return $footer . "\n" . Style::new()
            ->foreground($theme->shellForeground)
            ->render('  ' . self::clip(implode(' · ', $parts), $maxWidth));
    }

    /** $text cut to $width cells with a trailing ellipsis when it does not fit. */
    private static function clip(string $text, int $width): string
    {
        if (Width::string($text) <= $width) {
            return $text;
        }

        return Width::truncate($text, max(0, $width - 1)) . '…';
    }

    /** $text clipped and padded to exactly $width cells. */
    private static function fit(string $text, int $width): string
    {
        return Width::padRight(self::clip($text, $width), $width);
    }
}
