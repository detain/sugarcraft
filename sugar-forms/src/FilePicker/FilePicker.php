<?php

declare(strict_types=1);

namespace SugarCraft\Forms\FilePicker;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\HasThemeChrome;
use SugarCraft\Forms\Theme;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Util\ViewportPan;

/**
 * Directory browser. Lists the entries under {@see $cwd}, lets the user
 * navigate with arrow keys, descend into directories with Enter, and pop
 * back up with Backspace. {@see selected()} returns the path of the
 * highlighted entry the next time the user presses Enter on a file (or
 * any entry, when {@see $dirAllowed} is true).
 *
 * The directory listing is read on construction and on each {@see refresh()};
 * calling code that wants to reflect external changes should re-`refresh()`.
 *
 * Filtering:
 * - `showHidden` — when false (default), entries beginning with `.` are
 *   hidden.
 * - `allowedExtensions` — when non-empty, files whose extension isn't in
 *   the list are hidden. Always includes directories regardless.
 */
final class FilePicker implements Model
{
    use HasThemeChrome;

    private function __construct(
        public readonly string $cwd,
        /** @var list<Entry> */ public readonly array $entries,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $height,
        public readonly bool $focused,
        public readonly bool $showHidden,
        /** @var list<string> */ public readonly array $allowedExtensions,
        public readonly bool $dirAllowed,
        public readonly bool $fileAllowed,
        public readonly ?string $selected,
        public readonly bool $showIcons      = false,
        public readonly bool $showSize       = false,
        public readonly bool $directoryFirst = true,
        public readonly SortMode $sortBy     = SortMode::Name,
        public readonly bool $reverseSort    = false,
        public readonly ?string $error    = null,
        /** Horizontal budget in cells; 0 == natural width (uncapped). */
        public readonly int $width       = 0,
    ) {}

    /**
     * Construct a fresh instance with default state.
     *
     * `getcwd()` is deliberately snapshotted ONCE here when no $cwd is
     * given (E736 4.4): a TUI session never chdirs the process behind the
     * widget's back, so binding the directory at construction keeps every
     * later {@see refresh()} — and the value() emitted on submit — pointed
     * at the same tree the user browsed. Re-resolving per render would let
     * an embedding app's own chdir silently relocate the picker mid-session.
     */
    public static function new(?string $cwd = null, int $height = 10): self
    {
        $cwd = self::normalizeCwd($cwd ?? (string) getcwd());
        $self = new self(
            cwd:               $cwd,
            entries:           [],
            cursor:            0,
            offset:            0,
            height:            max(1, $height),
            focused:           false,
            showHidden:        false,
            allowedExtensions: [],
            dirAllowed:        false,
            fileAllowed:       true,
            selected:          null,
            directoryFirst:    true,
        );
        return $self->refresh();
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->moveCursor($this->cursor - 1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->moveCursor($this->cursor + 1), null],
            $msg->type === KeyType::Home
                => [$this->moveCursor(0), null],
            $msg->type === KeyType::End
                => [$this->moveCursor(PHP_INT_MAX), null],
            $msg->type === KeyType::Enter
                => [$this->activate(), null],
            $msg->type === KeyType::Backspace
                || $msg->type === KeyType::Left
                => [$this->ascend(), null],
            $msg->type === KeyType::Right
                => [$this->activate(), null],
            default => [$this, null],
        };
    }

    /**
     * Render the component as a multi-line ANSI string. SF-9: the theme
     * threads through from the FilePicker field — cursor rows ride the
     * theme cursor slot, entry rows the option slot, the error row the
     * error slot; null keeps the legacy hard-coded bytes.
     */
    public function view(?Theme $theme = null): string
    {
        $cap = $this->width;
        $fit = static fn(string $line): string => $cap > 0 && Width::of($line) > $cap
            ? Width::truncateMiddle($line, $cap)
            : $line;
        $lines = [$fit(RenderSafe::clean($this->cwd))];
        if ($this->error !== null) {
            // SF-008: the error carries a user-/filesystem-sourced path;
            // every sibling '! error' site in the lib renders it through
            // the sanitizer — this was the one unclean surface, letting
            // OSC-8/CSI smuggled via a crafted dir name paint the row.
            // Cap the message text before the theme wrap: truncateMiddle
            // is a strip-and-rebuild helper, so an SGR-carrying row must
            // not be handed to it already painted.
            $lines[] = self::themedErrorRow($fit(RenderSafe::clean($this->error)), $theme);
        }
        if ($this->entries === []) {
            $lines[] = '(empty)';
            return implode("\n", $lines);
        }

        $top    = max(0, $this->offset);
        $window = array_slice($this->entries, $top, $this->height);
        foreach ($window as $i => $entry) {
            $idx = $top + $i;
            $iconPart = $this->showIcons ? $entry->icon() . ' ' : '';
            $sizePart = $this->showSize  ? '  ' . $entry->formatSize() : '';
            $body     = $iconPart . RenderSafe::clean($entry->display()) . $sizePart;
            if ($cap > 0 && Width::of($body) > $cap - 2) {
                // Two prefix cells ('> ' / '  ') ride on top of the body
                // budget; the mid-truncation keeps the visible tail.
                $body = Width::truncateMiddle($body, max(1, $cap - 2));
            }
            $onCursor = $idx === $this->cursor && $this->focused;
            $line = ($onCursor ? '> ' : '  ') . self::themedOption($body, $theme);
            if ($onCursor) {
                $line = self::themedHighlight($line, $theme);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    public function selected(): ?string
    {
        return $this->selected;
    }

    public function highlightedEntry(): ?Entry
    {
        return $this->entries[$this->cursor] ?? null;
    }

    /**
     * Absolute path of the currently-highlighted entry, or null when
     * the listing is empty. Mirrors upstream Bubbles' `HighlightedPath`.
     */
    public function highlightedPath(): ?string
    {
        $entry = $this->highlightedEntry();
        return $entry?->path($this->cwd);
    }

    /** Configured viewport height. Mirrors upstream `Height()`. */
    public function height(): int
    {
        return $this->height;
    }

    /** Configured viewport width in cells (0 = natural). Mirrors upstream `Width()`. */
    public function width(): int
    {
        return $this->width;
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [$this->mutate(focused: true), null];
    }

    /** Release focus; companion to { focus()}. */
    public function blur(): self
    {
        return $this->mutate(focused: false);
    }

    public function setCwd(string $path): self
    {
        return $this->mutate(
            cwd: self::normalizeCwd($path),
            cursor: 0, offset: 0,
            selected: null, touchSelected: true,
        )->refresh();
    }

    /**
     * Trim trailing separators while preserving the root path. Without
     * this rule, `rtrim('/', '/')` collapses to `''` and `scandir('')`
     * raises a ValueError on PHP 8+.
     */
    private static function normalizeCwd(string $path): string
    {
        if ($path === '') {
            return DIRECTORY_SEPARATOR;
        }
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);
        return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
    }

    public function refresh(): self
    {
        // E736-F2/2.7 (round 85): a failed scandir used to surface as a bare
        // "(empty)" listing, indistinguishable from a genuinely empty
        // directory. The read error now rides the existing (until-now
        // never-written) error slot, which view() already renders as an
        // '! …' line. errorSet is unconditional so a repaired directory
        // clears the stale message on the next successful refresh.
        [$entries, $error] = $this->readDir();
        return $this->mutate(entries: $entries, error: $error, errorSet: true);
    }

    public function withShowHidden(bool $on): self
    {
        return $this->mutate(showHidden: $on)->refresh();
    }

    /** @param list<string> $exts */
    public function withAllowedExtensions(array $exts): self
    {
        $clean = array_values(array_map(static fn(string $e) => ltrim(strtolower($e), '.'), $exts));
        return $this->mutate(allowedExtensions: $clean)->refresh();
    }

    public function withDirAllowed(bool $on): self  { return $this->mutate(dirAllowed: $on); }
    public function withFileAllowed(bool $on): self { return $this->mutate(fileAllowed: $on); }
    public function withHeight(int $h): self        { return $this->mutate(height: max(1, $h))->reclamp(); }

    /**
     * Cap every rendered row at $cells terminal cells (0 restores the
     * natural width). Long paths are MIDDLE-truncated so the filename
     * tail — the part that identifies the entry — stays visible
      (sugar-forms-076, the Bubbles recipe).
     */
    public function withWidth(int $cells): self
    {
        return $this->mutate(width: max(0, $cells));
    }

    /** Render a per-entry icon glyph. Off by default. */
    public function withShowIcons(bool $on = true): self { return $this->mutate(showIcons: $on); }

    /** Append a right-aligned size column for files. Off by default. */
    public function withShowSize(bool $on = true): self  { return $this->mutate(showSize: $on); }

    /** Show directories before files. Enabled by default. */
    public function withDirectoryFirst(bool $first = true): self { return $this->mutate(directoryFirst: $first)->refresh(); }

    /**
     * Choose the secondary sort criterion (directories always group
     * first, regardless). Pass `$reverse: true` to flip order. Mirrors
     * Bubbles' sort options.
     */
    public function withSortMode(SortMode $mode, bool $reverse = false): self
    {
        return $this->mutate(sortBy: $mode, reverseSort: $reverse)->refresh();
    }

    /** Latest filesystem error (e.g. unreadable directory). */
    public function error(): ?string { return $this->error; }

    // ---- internals ---------------------------------------------------

    private function activate(): self
    {
        $entry = $this->highlightedEntry();
        if ($entry === null) {
            return $this;
        }
        if ($entry->isDir) {
            $next = $this->mutate(
                cwd:      $entry->path($this->cwd),
                cursor:   0,
                offset:   0,
                selected: $this->dirAllowed ? $entry->path($this->cwd) : null,
                touchSelected: true,
            );
            return $next->refresh();
        }
        if (!$this->fileAllowed) {
            return $this;
        }
        return $this->mutate(selected: $entry->path($this->cwd), touchSelected: true);
    }

    private function ascend(): self
    {
        $parent = dirname($this->cwd);
        if ($parent === $this->cwd) {
            return $this;
        }
        return $this->mutate(
            cwd: $parent, cursor: 0, offset: 0,
            selected: null, touchSelected: true,
        )->refresh();
    }

    /**
     * @return array{0: list<Entry>, 1: ?string} entries plus a surfaced read
     *         error (E736-F2/2.7): null on success, human-readable on failure
     */
    private function readDir(): array
    {
        $names = @scandir($this->cwd);
        if ($names === false) {
            // SF-017: localized like every other user-surfaced string;
            // the test-pinned 'cannot read directory: ' shape rides as the
            // en default so consumers see byte-identical text.
            return [[], Lang::t('filepicker.read_error', ['path' => $this->cwd])];
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $hidden = str_starts_with($name, '.');
            if ($hidden && !$this->showHidden) {
                continue;
            }
            $full   = Entry::join($this->cwd, $name);
            $isDir  = is_dir($full);
            if (!$isDir && $this->allowedExtensions !== []) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $this->allowedExtensions, true)) {
                    continue;
                }
            }
            $size  = !$isDir ? (int) @filesize($full) : 0;
            $mtime = (int) @filemtime($full);
            $entries[] = new Entry($name, $isDir, $hidden, $size, $mtime);
        }
        // Directories always group first when $directoryFirst is set;
        // secondary sort by configured mode.
        $reverse = $this->reverseSort ? -1 : 1;
        $mode = $this->sortBy;
        $dirFirst = $this->directoryFirst;
        usort($entries, static function (Entry $a, Entry $b) use ($mode, $reverse, $dirFirst): int {
            if ($dirFirst && $a->isDir !== $b->isDir) {
                return $a->isDir ? -1 : 1;
            }
            $cmp = match ($mode) {
                SortMode::Size  => $a->size  <=> $b->size,
                SortMode::MTime => $a->mtime <=> $b->mtime,
                SortMode::Name  => strnatcasecmp($a->name, $b->name),
            };
            return $cmp * $reverse;
        });
        return [$entries, null];
    }

    private function moveCursor(int $idx): self
    {
        $count = count($this->entries);
        if ($count === 0) {
            return $this->mutate(cursor: 0, offset: 0);
        }
        $cursor = max(0, min($count - 1, $idx));
        return $this->mutate(cursor: $cursor, offset: ViewportPan::offsetFor($cursor, $this->offset, $this->height));
    }

    private function reclamp(): self
    {
        return $this->moveCursor($this->cursor);
    }

    /** @param list<Entry>|null $entries @param list<string>|null $allowedExtensions */
    private function mutate(
        ?string $cwd = null,
        ?array $entries = null,
        ?int $cursor = null,
        ?int $offset = null,
        ?int $height = null,
        ?bool $focused = null,
        ?bool $showHidden = null,
        ?array $allowedExtensions = null,
        ?bool $dirAllowed = null,
        ?bool $fileAllowed = null,
        ?string $selected = null,
        bool $touchSelected = false,
        ?bool $showIcons = null,
        ?bool $showSize = null,
        ?bool $directoryFirst = null,
        ?SortMode $sortBy = null,
        ?bool $reverseSort = null,
        ?string $error = null, bool $errorSet = false,
        ?int $width = null,
    ): self {
        return new self(
            cwd:               $cwd               ?? $this->cwd,
            entries:           $entries           ?? $this->entries,
            cursor:            $cursor            ?? $this->cursor,
            offset:            $offset            ?? $this->offset,
            height:            $height            ?? $this->height,
            focused:           $focused           ?? $this->focused,
            showHidden:        $showHidden        ?? $this->showHidden,
            allowedExtensions: $allowedExtensions ?? $this->allowedExtensions,
            dirAllowed:        $dirAllowed        ?? $this->dirAllowed,
            fileAllowed:       $fileAllowed       ?? $this->fileAllowed,
            selected:          $touchSelected ? $selected : $this->selected,
            showIcons:         $showIcons         ?? $this->showIcons,
            showSize:          $showSize          ?? $this->showSize,
            directoryFirst:    $directoryFirst    ?? $this->directoryFirst,
            sortBy:            $sortBy            ?? $this->sortBy,
            reverseSort:       $reverseSort       ?? $this->reverseSort,
            error:             $errorSet ? $error : $this->error,
            width:             $width            ?? $this->width,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
