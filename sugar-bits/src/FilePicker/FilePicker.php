<?php

declare(strict_types=1);

namespace SugarCraft\Bits\FilePicker;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;

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
        public readonly bool $showIcons   = false,
        public readonly bool $showSize    = false,
        public readonly SortMode $sortBy  = SortMode::Name,
        public readonly bool $reverseSort = false,
        public readonly ?string $error    = null,
    ) {}

    /** Construct a fresh instance with default state. */
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

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $lines = [$this->cwd];
        if ($this->error !== null) {
            $lines[] = '! ' . $this->error;
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
            $body     = $iconPart . $entry->display() . $sizePart;
            $line = ($idx === $this->cursor && $this->focused ? '> ' : '  ') . $body;
            if ($idx === $this->cursor && $this->focused) {
                $line = Ansi::sgr(Ansi::REVERSE) . $line . Ansi::reset();
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

    /** Configured viewport width (currently unused — kept for API parity). */
    public function width(): int
    {
        return 0;
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
        return $this->mutate(entries: $this->readDir());
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

    /** Render a per-entry icon glyph. Off by default. */
    public function withShowIcons(bool $on = true): self { return $this->mutate(showIcons: $on); }

    /** Append a right-aligned size column for files. Off by default. */
    public function withShowSize(bool $on = true): self  { return $this->mutate(showSize: $on); }

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

    /** @return list<Entry> */
    private function readDir(): array
    {
        $names = @scandir($this->cwd);
        if ($names === false) {
            return [];
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
            $full   = rtrim($this->cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
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
        // Directories always group first; secondary sort by configured mode.
        $reverse = $this->reverseSort ? -1 : 1;
        $mode = $this->sortBy;
        usort($entries, static function (Entry $a, Entry $b) use ($mode, $reverse): int {
            if ($a->isDir !== $b->isDir) {
                return $a->isDir ? -1 : 1;
            }
            $cmp = match ($mode) {
                SortMode::Size  => $a->size  <=> $b->size,
                SortMode::MTime => $a->mtime <=> $b->mtime,
                SortMode::Name  => strnatcasecmp($a->name, $b->name),
            };
            return $cmp * $reverse;
        });
        return $entries;
    }

    private function moveCursor(int $idx): self
    {
        $count = count($this->entries);
        if ($count === 0) {
            return $this->mutate(cursor: 0, offset: 0);
        }
        $cursor = max(0, min($count - 1, $idx));
        $offset = $this->offset;
        if ($cursor < $offset) {
            $offset = $cursor;
        }
        if ($this->height > 0 && $cursor >= $offset + $this->height) {
            $offset = $cursor - $this->height + 1;
        }
        return $this->mutate(cursor: $cursor, offset: max(0, $offset));
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
        ?SortMode $sortBy = null,
        ?bool $reverseSort = null,
        ?string $error = null, bool $errorSet = false,
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
            sortBy:            $sortBy            ?? $this->sortBy,
            reverseSort:       $reverseSort       ?? $this->reverseSort,
            error:             $errorSet ? $error : $this->error,
        );
    }
}
