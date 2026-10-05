<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\DirectoryPicker;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Support\Directories\DirectoryBrowser;
use SugarCraft\Crush\Support\Directories\DirectoryBrowserException;
use SugarCraft\Crush\Support\Directories\DirectoryListing;
use SugarCraft\Crush\Theme;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * The `/new` folder picker: choose the directory a new session starts in.
 *
 * Opens on the current project root with "▶ Start session here" highlighted,
 * so `/new` + Enter is the plain new session it always was. Browsing is the
 * shared {@see DirectoryBrowser} (the one behind the web UI's `fs.listDirs`),
 * UNROOTED here — the local TUI is the user's own machine — so `..` climbs to
 * `/`; a directory this process cannot read is refused rather than entered,
 * and links are listed by their resolved path.
 *
 * Keys (also in {@see \SugarCraft\Crush\Commands\KeyBindingRegistry}):
 *  - ↑/↓ (k/j), PgUp/PgDn, Home/End move; Enter on a directory or → (l)
 *    opens it; Enter on "▶ Start session here" or `s` chooses the directory
 *    on screen; Backspace or ← (h) goes to the parent; `.` shows or hides
 *    dot-directories; `/` types or pastes a path (Enter goes, Esc stops);
 *    Esc (q) cancels.
 *  - Choosing a directory that is NOT the current project root asks first —
 *    sugar-crush serves one root per process, so the answer is a restart
 *    there — `y`/Enter confirms, `n`/Esc does not.
 *
 * Display state only: the decision comes back as a
 * {@see DirectoryPickerAction} for {@see \SugarCraft\Crush\Chat} to carry
 * out. Every painted line is fitted to the width it is handed.
 */
final class DirectoryPicker
{
    /** Header lines above the box: title, rule, key hints. */
    private const HEADER_LINES = 3;

    /** Border (2) and the footer (path, status) around the body. */
    private const CHROME_LINES = 4;

    private const PAGE = 10;

    private function __construct(
        private readonly DirectoryBrowser $browser,
        private readonly string $currentRoot,
        private readonly DirectoryListing $listing,
        private readonly int $selected,
        private readonly bool $showHidden,
        private readonly ?string $typed,
        private readonly ?string $confirm,
        private readonly ?string $notice,
    ) {
    }

    /**
     * A picker on $root (the current project root): the directory a plain
     * Enter starts the session in. Falls back to the process directory, then
     * `/`, when $root cannot be listed.
     */
    public static function open(string $root): self
    {
        $browser = DirectoryBrowser::new();
        $current = \realpath($root);
        $current = $current === false ? $root : $current;
        $listing = null;
        foreach ([$root, \getcwd() ?: '/', '/'] as $candidate) {
            try {
                $listing = $browser->list($candidate);
                break;
            } catch (DirectoryBrowserException) {
                continue;
            }
        }

        return new self($browser, $current, $listing ?? new DirectoryListing('/', null, [], false, false, false), 0, false, null, null, null);
    }

    /**
     * `/new <path>`: skip the browsing and choose $path as if picked — a
     * different root still asks for confirmation in the picker. A path that
     * does not resolve leaves the picker open on the root with the reason.
     *
     * @return array{0: self, 1: ?DirectoryPickerAction}
     */
    public function chooseTyped(string $path): array
    {
        try {
            $real = $this->browser->resolve($path, $this->currentRoot);
        } catch (DirectoryBrowserException $e) {
            return [$this->with(notice: $e->getMessage()), null];
        }

        return $this->choose($real);
    }

    /** The directory on screen. */
    public function path(): string
    {
        return $this->listing->path;
    }

    /** 0 = "▶ Start session here", then one per listed directory. */
    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function showsHidden(): bool
    {
        return $this->showHidden;
    }

    /** The path being typed after `/`, or null when not typing. */
    public function typedPath(): ?string
    {
        return $this->typed;
    }

    /** The directory a restart is waiting to be confirmed for. */
    public function confirming(): ?string
    {
        return $this->confirm;
    }

    public function notice(): ?string
    {
        return $this->notice;
    }

    public function listing(): DirectoryListing
    {
        return $this->listing;
    }

    /**
     * Pasted text: into the path being typed (starting one if none is).
     */
    public function withPaste(string $text): self
    {
        $line = \trim((string) \preg_replace('/[\x00-\x1f\x7f]+/', '', \str_replace(["\r", "\n"], ' ', $text)));

        return $this->with(typed: ($this->typed ?? '') . $line, notice: null);
    }

    /** @return array{0: self, 1: ?DirectoryPickerAction} */
    public function update(KeyMsg $msg): array
    {
        if ($this->confirm !== null) {
            return $this->updateConfirm($msg);
        }
        if ($this->typed !== null) {
            return [$this->updateTyping($msg), null];
        }

        $rune = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt ? $msg->rune : '';
        $count = \count($this->listing->entries) + 1;

        return match (true) {
            $msg->type === KeyType::Escape, $rune === 'q' => [$this, DirectoryPickerAction::cancel()],
            $msg->type === KeyType::Up, $rune === 'k' => [$this->select($this->selected - 1), null],
            $msg->type === KeyType::Down, $rune === 'j' => [$this->select($this->selected + 1), null],
            $msg->type === KeyType::PageUp => [$this->select($this->selected - self::PAGE), null],
            $msg->type === KeyType::PageDown => [$this->select($this->selected + self::PAGE), null],
            $msg->type === KeyType::Home => [$this->select(0), null],
            $msg->type === KeyType::End => [$this->select($count - 1), null],
            $msg->type === KeyType::Enter => $this->selected === 0 ? $this->choose($this->listing->path) : [$this->descend(), null],
            $msg->type === KeyType::Right, $rune === 'l' => [$this->descend(), null],
            $msg->type === KeyType::Backspace, $msg->type === KeyType::Left, $rune === 'h' => [$this->up(), null],
            $rune === '.' => [$this->go($this->listing->path, !$this->showHidden), null],
            $rune === '/' => [$this->with(typed: '', notice: null), null],
            $rune === 's' => $this->choose($this->listing->path),
            default => [$this, null],
        };
    }

    /** @return array{0: self, 1: ?DirectoryPickerAction} */
    private function updateConfirm(KeyMsg $msg): array
    {
        $rune = $msg->type === KeyType::Char ? \strtolower($msg->rune) : '';
        if ($msg->type === KeyType::Enter || $rune === 'y') {
            return [$this, DirectoryPickerAction::relaunch((string) $this->confirm)];
        }
        if ($msg->type === KeyType::Escape || $rune === 'n') {
            return [$this->with(confirm: null), null];
        }

        return [$this, null];
    }

    private function updateTyping(KeyMsg $msg): self
    {
        $typed = (string) $this->typed;

        return match (true) {
            $msg->type === KeyType::Escape => $this->with(typed: null),
            $msg->type === KeyType::Enter => \trim($typed) === ''
                ? $this->with(typed: null)
                : $this->with(typed: null)->go(\trim($typed), $this->showHidden),
            $msg->type === KeyType::Backspace => $this->with(typed: \mb_substr($typed, 0, \max(0, \mb_strlen($typed) - 1))),
            $msg->type === KeyType::Space => $this->with(typed: $typed . ' '),
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune !== '' => $this->with(typed: $typed . $msg->rune),
            default => $this,
        };
    }

    /** @return array{0: self, 1: ?DirectoryPickerAction} */
    private function choose(string $path): array
    {
        if ($path === $this->currentRoot) {
            return [$this, DirectoryPickerAction::here($path)];
        }

        return [$this->with(confirm: $path, notice: null), null];
    }

    private function descend(): self
    {
        $entry = $this->listing->entries[$this->selected - 1] ?? null;
        if ($entry === null) {
            return $this;
        }
        if (!$entry->readable) {
            return $this->with(notice: Lang::t('tui.dirpicker.unreadable', ['path' => $entry->path]));
        }

        return $this->go($entry->path, $this->showHidden);
    }

    private function up(): self
    {
        $parent = $this->listing->parent;

        return $parent === null ? $this : $this->go($parent, $this->showHidden, \basename($this->listing->path));
    }

    /** List $path; land on $focus (a child's name) when given, else on "start here". */
    private function go(string $path, bool $showHidden, ?string $focus = null): self
    {
        try {
            $listing = $this->browser->list($path, $showHidden, $this->listing->path);
        } catch (DirectoryBrowserException $e) {
            return $this->with(notice: $e->getMessage());
        }
        if (!$listing->readable) {
            return $this->with(notice: Lang::t('tui.dirpicker.unreadable', ['path' => $listing->path]));
        }
        $selected = 0;
        if ($focus !== null) {
            foreach ($listing->entries as $i => $entry) {
                if ($entry->name === $focus) {
                    $selected = $i + 1;
                    break;
                }
            }
        } elseif ($listing->path === $this->listing->path) {
            $selected = \min($this->selected, \count($listing->entries));
        }

        return new self($this->browser, $this->currentRoot, $listing, $selected, $showHidden, null, null, null);
    }

    private function select(int $index): self
    {
        $max = \count($this->listing->entries);

        return $this->with(selected: \max(0, \min($max, $index)), notice: null);
    }

    // ── painting ────────────────────────────────────────────────────────

    /**
     * The box's content width and total height for a terminal of $cols ×
     * $rows, inside a shell whose frame takes $shellChromeCols — the same
     * rule {@see \SugarCraft\Crush\Tui\SessionPicker::overlayGeometry()}
     * sizes the session list by. The painted box is 4 columns wider (border
     * and padding).
     *
     * @return array{0: int, 1: int}
     */
    public static function overlayGeometry(int $cols, int $rows, int $shellChromeCols): array
    {
        $inner = \max(20, $cols - $shellChromeCols);

        return [\max(16, \min($inner - 4, 76)), \max(8, $rows - 4)];
    }

    public function render(int $width, int $height, Theme $theme): string
    {
        $body = \max(1, $height - self::HEADER_LINES - self::CHROME_LINES);
        $rows = $this->rowLabels();
        [$from, $to] = self::window(\count($rows), $this->selected, $body);

        $lines = [];
        for ($i = $from; $i < $to; $i++) {
            $line = self::fit($rows[$i], $width);
            $style = Style::new();
            if ($i === $this->selected) {
                $style = $style->reverse();
            } elseif ($i === 0) {
                $style = $style->foreground($theme->shellPrimary)->bold();
            } elseif (!$this->listing->entries[$i - 1]->readable) {
                $style = $style->foreground($theme->shellMuted);
            }
            $lines[] = $style->render($line);
        }
        if (\count($rows) === 1) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit(
                '  ' . Lang::t($this->listing->readable ? 'tui.dirpicker.empty' : 'tui.dirpicker.cannot_read'),
                $width,
            ));
        }
        if ($this->listing->truncated) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit('  ' . Lang::t('tui.dirpicker.truncated'), $width));
        }

        $box = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.dirpicker.box_title') . ' '))
            ->borderForeground($this->confirm !== null ? $theme->shellWarning : $theme->shellPrimary)
            ->padding(0, 1)
            ->width($width);

        return $this->renderHeader($width, $theme) . "\n" . $box->render(\implode("\n", $lines)) . "\n" . $this->renderFooter($width, $theme);
    }

    /**
     * The body rows as plain text: "▶ Start session here", then each
     * directory with its project badge.
     *
     * @return list<string>
     */
    public function rowLabels(): array
    {
        $rows = ['▶ ' . Lang::t('tui.dirpicker.start_here')];
        foreach ($this->listing->entries as $entry) {
            $rows[] = '  ' . $entry->name . '/'
                . ($entry->project ? '  [' . Lang::t('tui.dirpicker.project') . ']' : '')
                . ($entry->readable ? '' : '  (' . Lang::t('tui.dirpicker.no_access') . ')');
        }

        return $rows;
    }

    private function renderHeader(int $width, Theme $theme): string
    {
        $title = ' ' . Lang::t('tui.dirpicker.title') . ($this->showHidden ? ' · ' . Lang::t('tui.dirpicker.hidden_on') : '');
        $hints = ' ' . Lang::t(match (true) {
            $this->confirm !== null => 'tui.dirpicker.hints.confirm',
            $this->typed !== null => 'tui.dirpicker.hints.typing',
            default => 'tui.dirpicker.hints.browse',
        });

        return Style::new()->foreground($theme->shellPrimary)->bold()->render(self::fit($title, $width + 4))
            . "\n" . Style::new()->foreground($theme->border)->render(\str_repeat('─', \max(0, $width + 4)))
            . "\n" . Style::new()->foreground($theme->shellMuted)->render(self::fit($hints, $width + 4));
    }

    /** The directory on screen (cut from the left), then a typed path, a confirm question or a notice. */
    private function renderFooter(int $width, Theme $theme): string
    {
        // The restart question takes both footer lines: it names the
        // directory itself, and cut to one line its "(y/n)" would be lost.
        if ($this->confirm !== null) {
            [$first, $second] = self::twoLines(Lang::t('tui.dirpicker.confirm', ['path' => $this->confirm]), $width + 3);
            $warn = Style::new()->foreground($theme->shellWarning)->bold();

            return $warn->render(' ' . $first) . "\n" . $warn->render(' ' . $second);
        }
        $path = ' ' . self::fitLeft($this->listing->path . ($this->listing->project ? '  [' . Lang::t('tui.dirpicker.project') . ']' : ''), $width + 3);
        $status = match (true) {
            $this->confirm !== null => Style::new()->foreground($theme->shellWarning)->bold()->render(self::fit(
                ' ' . Lang::t('tui.dirpicker.confirm', ['path' => $this->confirm]),
                $width + 4,
            )),
            $this->typed !== null => Style::new()->foreground($theme->shellPrimary)->render(
                ' ' . Lang::t('tui.dirpicker.path_prompt') . ' ' . self::fitLeft($this->typed . '▏', \max(1, $width + 3 - Width::string(Lang::t('tui.dirpicker.path_prompt')) - 1)),
            ),
            $this->notice !== null => Style::new()->foreground($theme->shellWarning)->render(self::fit(' ' . $this->notice, $width + 4)),
            default => '',
        };

        return Style::new()->foreground($theme->shellMuted)->render($path) . "\n" . $status;
    }

    /**
     * $text over at most two lines of $width cells, broken between words; a
     * second line that still does not fit keeps its END (where "(y/n)" is).
     *
     * @return array{0: string, 1: string}
     */
    private static function twoLines(string $text, int $width): array
    {
        $text = (string) \preg_replace('/[\x00-\x1f\x7f]+/u', '', $text);
        if (Width::string($text) <= $width) {
            return [$text, ''];
        }
        $first = '';
        $words = \explode(' ', $text);
        while ($words !== [] && Width::string(\ltrim($first . ' ' . $words[0])) <= $width) {
            $first = \ltrim($first . ' ' . \array_shift($words));
        }
        if ($first === '') {
            return [self::fit($text, $width), self::fitLeft($text, $width)];
        }

        return [$first, self::fitLeft(\implode(' ', $words), $width)];
    }

    /**
     * The slice of $count rows a body $height tall shows, keeping $selected
     * in view (centred once it is past the first screen).
     *
     * @return array{0: int, 1: int}
     */
    private static function window(int $count, int $selected, int $height): array
    {
        if ($count <= $height) {
            return [0, $count];
        }
        $from = \max(0, \min($selected - \intdiv($height, 2), $count - $height));

        return [$from, $from + $height];
    }

    /** $text cut (with "…") or space-padded to exactly $width cells; control bytes dropped. */
    private static function fit(string $text, int $width): string
    {
        $text = (string) \preg_replace('/[\x00-\x1f\x7f]+/u', '', $text);
        if ($width <= 0) {
            return '';
        }
        if (Width::string($text) > $width) {
            $out = '';
            foreach (\mb_str_split($text) as $char) {
                if (Width::string($out . $char) > $width - 1) {
                    break;
                }
                $out .= $char;
            }
            $text = $out . '…';
        }

        return $text . \str_repeat(' ', \max(0, $width - Width::string($text)));
    }

    /** $text cut from the LEFT ("…/tail") to at most $width cells: a path's end is what tells it apart. */
    private static function fitLeft(string $text, int $width): string
    {
        $text = (string) \preg_replace('/[\x00-\x1f\x7f]+/u', '', $text);
        if ($width <= 0) {
            return '';
        }
        if (Width::string($text) <= $width) {
            return $text;
        }
        $chars = \mb_str_split($text);
        $out = '';
        for ($i = \count($chars) - 1; $i >= 0; $i--) {
            if (Width::string($chars[$i] . $out) > $width - 1) {
                break;
            }
            $out = $chars[$i] . $out;
        }

        return '…' . $out;
    }

    private function with(
        ?int $selected = null,
        string|false|null $typed = false,
        string|false|null $confirm = false,
        string|false|null $notice = false,
    ): self {
        return new self(
            $this->browser,
            $this->currentRoot,
            $this->listing,
            $selected ?? $this->selected,
            $this->showHidden,
            $typed === false ? $this->typed : $typed,
            $confirm === false ? $this->confirm : $confirm,
            $notice === false ? $this->notice : $notice,
        );
    }
}
