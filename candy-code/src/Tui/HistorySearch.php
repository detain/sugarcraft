<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

use function array_reverse;
use function count;
use function mb_str_split;
use function mb_stripos;
use function mb_strlen;
use function mb_substr;
use function max;
use function min;
use function preg_replace;
use function str_repeat;

/**
 * The Ctrl+G reverse history search (plan_comparison TUI-2), an overlay state
 * machine cloned from {@see \SugarCraft\Code\Tui\DirectoryPicker\DirectoryPicker}'s
 * keyboard-owned shape: the widget decides, `Chat` applies.
 *
 * Rows are the recalled prompts newest-first; typing filters them by
 * case-insensitive containment in real time (recency IS the rank — the freshest
 * match is always the first row, so plain ↑/↓ walk a hit list ordered the way
 * the user already thinks, and Claude Code's `ctrl+r` "most recent match
 * first" is the same decision). A repeated Ctrl+G — which reaches here only
 * while this overlay is open, because `Chat` routes every key to `update()`
 * first, exactly like the session picker's re-press machinery — cycles the
 * highlight to the NEXT match rather than reopening, which is the reverse-i-
 * search idiom the plan names.
 *
 * The overlay never writes the draft while it is open: it holds the draft and
 * cursor it was opened on as its SEED, and `Chat` restores that seed on
 * Escape. That is what keeps the recall walk's seed/recall law (the draft the
 * user was typing is not fuel for the search) intact through a cancelled
 * search — Enter, in contrast, accepts the highlighted entry into the draft
 * WITHOUT submitting it, so the user can edit before sending.
 */
final class HistorySearch
{
    /** Title and query lines above the box. */
    private const HEADER_LINES = 2;

    /** Border (2) and the hint footer (1) around the body. */
    private const CHROME_LINES = 3;

    /**
     * @param list<string> $entries newest-first recalled prompts
     * @param list<string> $matches last derived hit list (render/window only)
     */
    private function __construct(
        private readonly array $entries,
        private readonly string $query,
        private readonly int $selected,
        private readonly string $seedDraft,
        private readonly int $seedCursor,
        private readonly array $matches,
    ) {
    }

    /**
     * A search over $entries as recalled (oldest-first); reversed here, once,
     * so every later read is newest-first.
     */
    public static function open(array $entriesOldestFirst, string $seedDraft, int $seedCursor): self
    {
        return new self(array_values(array_reverse(array_values($entriesOldestFirst))), '', 0, $seedDraft, $seedCursor, []);
    }

    public function query(): string
    {
        return $this->query;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    /** The highlighted entry, or null when the filter matches nothing. */
    public function selectedEntry(): ?string
    {
        return $this->matches()[$this->selected] ?? null;
    }

    /** @return list<string> the filter result, newest-first */
    public function matches(): array
    {
        if ($this->matches !== []) {
            return $this->matches;
        }

        return $this->computeMatches();
    }

    /**
     * The draft and cursor the overlay was opened on, as {@see self}
     * constructor captured them — `Chat` restores these verbatim on Escape.
     *
     * @return array{0: string, 1: int}
     */
    public function seed(): array
    {
        return [$this->seedDraft, $this->seedCursor];
    }

    /**
     * Pasted text joins the query on one line, never the draft: while the
     * search is up the overlay owns typing, and a bracketed paste arrives on
     * its own Msg channel, beside the key routing — the same door the folder
     * picker's `withPaste` uses at the PasteMsg arm.
     */
    public function withPaste(string $text): self
    {
        $line = (string) \preg_replace('/[\x00-\x1f\x7f]+/', '', \str_replace(["\r", "\n"], ' ', $text));

        return $this->with($this->query . $line);
    }

    /**
     * Feed the overlay one keystroke.
     *
     * @return array{0: self, 1: ?string} [next, action] with action in
     *         {null, 'close', 'accept'} — strings, like the session picker's
     *         actions, because there are exactly two and neither carries data.
     */
    public function update(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Escape => [$this, 'close'],
            $msg->type === KeyType::Enter => $this->selectedEntry() === null ? [$this, null] : [$this, 'accept'],
            $msg->type === KeyType::Up => [$this->select($this->selected - 1), null],
            $msg->type === KeyType::Down => [$this->select($this->selected + 1), null],
            $msg->type === KeyType::Backspace => [$this->with(query: mb_substr($this->query, 0, max(0, mb_strlen($this->query, 'UTF-8') - 1))), null],
            $msg->type === KeyType::Space => [$this->with(query: $this->query . ' '), null],
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'g' => [$this->select($this->selected + 1), null],
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune !== '' => [$this->with(query: $this->query . $msg->rune), null],
            default => [$this, null],
        };
    }

    /**
     * The overlay box geometry, the same rule as the other Chat overlays
     * ({@see \SugarCraft\Code\Tui\DirectoryPicker\DirectoryPicker::overlayGeometry()}).
     *
     * @return array{0: int, 1: int}
     */
    public static function overlayGeometry(int $cols, int $rows, int $shellChromeCols): array
    {
        $inner = max(20, $cols - $shellChromeCols);

        return [max(16, min($inner - 4, 76)), max(8, $rows - 4)];
    }

    public function render(int $width, int $height, Theme $theme): string
    {
        $body = max(1, $height - self::HEADER_LINES - self::CHROME_LINES);
        $rows = $this->matches();
        [$from, $to] = self::window(count($rows), $this->selected, $body);

        $lines = [];
        for ($i = $from; $i < $to; $i++) {
            $style = Style::new();
            if ($i === $this->selected) {
                $style = $style->reverse();
            }
            $lines[] = $style->render(self::fit('  ' . $rows[$i], $width));
        }
        if ($rows === []) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit(
                '  ' . Lang::t('tui.history.no_match'),
                $width,
            ));
        }
        if ($from > 0) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit('  ' . Lang::t('tui.history.more_above', ['count' => $from]), $width));
        }
        if ($to < count($rows)) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit('  ' . Lang::t('tui.history.more_below', ['count' => count($rows) - $to]), $width));
        }

        $box = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.history.box_title') . ' '))
            ->borderForeground($theme->shellPrimary)
            ->padding(0, 1)
            ->width($width);

        $title = Style::new()->foreground($theme->shellPrimary)->bold()
            ->render(self::fit(' ' . Lang::t('tui.history.title'), $width + 4));
        $rule = Style::new()->foreground($theme->border)->render(str_repeat('─', max(0, $width + 4)));
        $caret = self::fit(self::clipFromRight($this->query . '▏', max(1, $width + 1)), max(1, $width + 1));
        $queryLine = Style::new()->foreground($theme->shellPrimary)->render(' ' . $caret);
        $footer = Style::new()->foreground($theme->shellMuted)
            ->render(self::fit(' ' . Lang::t('tui.history.hints'), $width + 4));

        return $title . "\n" . $rule . "\n" . $queryLine . "\n"
            . $box->render(implode("\n", $lines)) . "\n"
            . $footer;
    }

    /** @return list<string> */
    private function computeMatches(): array
    {
        if ($this->query === '') {
            return $this->entries;
        }

        $hits = [];
        foreach ($this->entries as $entry) {
            if (mb_stripos($entry, $this->query) !== false) {
                $hits[] = $entry;
            }
        }

        return $hits;
    }

    /** Re-derive matches for a new query: the highlight restarts at the top. */
    private function with(string $query): self
    {
        $next = new self($this->entries, $query, 0, $this->seedDraft, $this->seedCursor, []);

        return $next;
    }

    /** Move the highlight with wrap; vacuous on an empty hit list. */
    private function select(int $index): self
    {
        $count = count($this->matches());
        if ($count === 0) {
            return $this;
        }

        return new self($this->entries, $this->query, (($index % $count) + $count) % $count, $this->seedDraft, $this->seedCursor, $this->matches());
    }

    /**
     * The window of rows the body shows: a centered slice around $selected of
     * at most $body rows, as [from, to) — the folder picker's rule.
     *
     * @return array{0: int, 1: int}
     */
    private static function window(int $count, int $selected, int $body): array
    {
        if ($count <= $body) {
            return [0, $count];
        }
        $from = max(0, min($selected - intdiv($body, 2), $count - $body));

        return [$from, $from + $body];
    }

    /** Cut $text to $width cells with a trailing ellipsis; control bytes out. */
    private static function fit(string $text, int $width): string
    {
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $text);
        if ($width <= 0) {
            return '';
        }
        if (Width::string($text) > $width) {
            $out = '';
            foreach (mb_str_split($text) as $char) {
                if (Width::string($out . $char) > $width - 1) {
                    break;
                }
                $out .= $char;
            }
            $text = $out . '…';
        }

        return $text;
    }

    /** Keep the query's TAIL (where typing happens) inside $cells cells. */
    private static function clipFromRight(string $text, int $cells): string
    {
        if (Width::string($text) <= $cells) {
            return $text;
        }
        $keep = ltrim($text, ' ');
        while ($keep !== '' && Width::string('…' . $keep) > $cells) {
            $keep = mb_substr($keep, 1);
        }

        return $keep === '' ? '…' : '…' . $keep;
    }
}
