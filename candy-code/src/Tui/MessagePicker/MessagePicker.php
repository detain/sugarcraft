<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MessagePicker;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

use function count;
use function mb_str_split;
use function mb_stripos;
use function mb_strlen;
use function mb_substr;
use function max;
use function min;
use function preg_replace;
use function str_repeat;
use function str_pad;

/**
 * The message picker (plan_comparison C-TUI-3, GAP-UI-15): a pure overlay
 * state machine in the {@see \SugarCraft\Code\Tui\HistorySearch} shape — the
 * widget decides, the host applies — whose rows are the session's
 * conversation checkpoints, each labelled with the prompt that checkpoint
 * restores.
 *
 * The host opens it with the checkpoint prompts in the order the store
 * reports them ({@see \SugarCraft\Code\Session\EnhancedSessionStore::listCheckpoints()}
 * — newest first), so a row's position IS the rewind position the `--chat`
 * form of `/rewind` counts: the first row is one step back, the second two.
 * Typing filters the rows by case-insensitive containment in real time;
 * recency stays the rank, so the freshest match is always the first row.
 * Accepting a filtered row still reports the row's ORIGINAL position, never
 * its place in the hit list — filtering moves what you see, not what a
 * rewind step counts back through.
 *
 * Escape answers {@see MessagePickerAction::close()} and Enter answers
 * {@see MessagePickerAction::accept(int)}; {@see self::update()} returns
 * them beside the next state, and neither writes to the conversation itself.
 * That is the DirectoryPicker's keyboard-owned law again: every mutation of
 * the session belongs to the host that reads this class's answer.
 */
final class MessagePicker
{
    /** Title and query lines above the box. */
    private const HEADER_LINES = 2;

    /** Border (2) and the hint footer (1) around the body. */
    private const CHROME_LINES = 3;

    /**
     * @param list<array{stepsBack: int, prompt: string}> $rows newest-first, each with its rewind position
     * @param list<array{stepsBack: int, prompt: string}> $matches last derived hit list (render/window only)
     */
    private function __construct(
        private readonly array $rows,
        private readonly string $query,
        private readonly int $selected,
        private readonly array $matches,
    ) {
    }

    /**
     * A picker over the checkpoint prompts as the store listed them —
     * newest-first. Row positions become the rewind steps from one.
     *
     * @param list<string> $promptsNewestFirst
     */
    public static function open(array $promptsNewestFirst): self
    {
        $rows = [];
        $stepsBack = 1;
        foreach ($promptsNewestFirst as $prompt) {
            $rows[] = ['stepsBack' => $stepsBack, 'prompt' => (string) $prompt];
            $stepsBack++;
        }

        return new self($rows, '', 0, []);
    }

    public function query(): string
    {
        return $this->query;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    /** @return list<array{stepsBack: int, prompt: string}> every row, newest-first */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<array{stepsBack: int, prompt: string}> the filter result, newest-first */
    public function matches(): array
    {
        if ($this->matches !== []) {
            return $this->matches;
        }

        return $this->computeMatches();
    }

    /** The highlighted row, or null when there is nothing to highlight. */
    public function selectedRow(): ?array
    {
        return $this->matches()[$this->selected] ?? null;
    }

    /**
     * The rewind position of the highlighted row — what an Accept reports —
     * or null when the filter matches nothing.
     */
    public function selectedStepsBack(): ?int
    {
        $row = $this->selectedRow();

        return $row === null ? null : $row['stepsBack'];
    }

    /**
     * Feed the overlay one keystroke.
     *
     * @return array{0: self, 1: ?MessagePickerAction} [next, action]
     */
    public function update(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Escape => [$this, MessagePickerAction::close()],
            $msg->type === KeyType::Enter => $this->accept(),
            $msg->type === KeyType::Up => [$this->select($this->selected - 1), null],
            $msg->type === KeyType::Down => [$this->select($this->selected + 1), null],
            $msg->type === KeyType::Backspace => [$this->withQuery(mb_substr($this->query, 0, max(0, mb_strlen($this->query, 'UTF-8') - 1))), null],
            $msg->type === KeyType::Space => [$this->withQuery($this->query . ' '), null],
            $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $msg->rune !== '' => [$this->withQuery($this->query . $msg->rune), null],
            default => [$this, null],
        };
    }

    /**
     * The whole overlay box for a terminal of $cols x $rows: the title, the
     * live query line, the windowed row list and the hint footer, drawn with
     * $theme. $shellChromeCols is the frame the host already spends on the
     * shell (the other Chat overlays pass theirs; the picker stands alone at 0).
     */
    public function view(int $cols, int $rows, Theme $theme, int $shellChromeCols = 0): string
    {
        [$width, $height] = self::overlayGeometry($cols, $rows, $shellChromeCols);
        $body = max(1, $height - self::HEADER_LINES - self::CHROME_LINES);
        $hits = $this->matches();
        [$from, $to] = self::window(count($hits), $this->selected, $body);

        $lines = [];
        for ($i = $from; $i < $to; $i++) {
            $style = Style::new();
            if ($i === $this->selected) {
                $style = $style->reverse();
            }
            $lines[] = $style->render(self::fit(
                ' ' . str_pad((string) $hits[$i]['stepsBack'], 2, ' ', STR_PAD_LEFT) . ' ' . $hits[$i]['prompt'],
                $width,
            ));
        }
        if ($hits === []) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit(
                '  ' . Lang::t($this->rows === [] ? 'tui.msgpicker.empty' : 'tui.msgpicker.no_match'),
                $width,
            ));
        }
        if ($from > 0) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit('  ' . Lang::t('tui.msgpicker.more_above', ['count' => $from]), $width));
        }
        if ($to < count($hits)) {
            $lines[] = Style::new()->foreground($theme->shellMuted)->render(self::fit('  ' . Lang::t('tui.msgpicker.more_below', ['count' => count($hits) - $to]), $width));
        }

        $box = Style::new()
            ->border(Border::rounded()->withTitle(' ' . Lang::t('tui.msgpicker.box_title') . ' '))
            ->borderForeground($theme->shellPrimary)
            ->padding(0, 1)
            ->width($width);

        $title = Style::new()->foreground($theme->shellPrimary)->bold()
            ->render(self::fit(' ' . Lang::t('tui.msgpicker.title'), $width + 4));
        $rule = Style::new()->foreground($theme->border)->render(str_repeat('─', max(0, $width + 4)));
        $caret = self::fit(self::clipFromRight($this->query . '▏', max(1, $width + 1)), max(1, $width + 1));
        $queryLine = Style::new()->foreground($theme->shellPrimary)->render(' ' . $caret);
        $footer = Style::new()->foreground($theme->shellMuted)
            ->render(self::fit(' ' . Lang::t('tui.msgpicker.hints'), $width + 4));

        return $title . "\n" . $rule . "\n" . $queryLine . "\n"
            . $box->render(implode("\n", $lines)) . "\n"
            . $footer;
    }

    /**
     * The overlay box geometry, the same rule as the other Chat overlays
     * ({@see \SugarCraft\Code\Tui\HistorySearch::overlayGeometry()}).
     *
     * @return array{0: int, 1: int}
     */
    public static function overlayGeometry(int $cols, int $rows, int $shellChromeCols): array
    {
        $inner = max(20, $cols - $shellChromeCols);

        return [max(16, min($inner - 4, 76)), max(8, $rows - 4)];
    }

    /** @return array{0: self, 1: ?MessagePickerAction} */
    private function accept(): array
    {
        $stepsBack = $this->selectedStepsBack();

        return $stepsBack === null ? [$this, null] : [$this, MessagePickerAction::accept($stepsBack)];
    }

    /** @return list<array{stepsBack: int, prompt: string}> */
    private function computeMatches(): array
    {
        if ($this->query === '') {
            return $this->rows;
        }

        $hits = [];
        foreach ($this->rows as $row) {
            if (mb_stripos($row['prompt'], $this->query) !== false) {
                $hits[] = $row;
            }
        }

        return $hits;
    }

    /** Re-derive matches for a new query: the highlight restarts at the top. */
    private function withQuery(string $query): self
    {
        return new self($this->rows, $query, 0, []);
    }

    /** Move the highlight with wrap; vacuous on an empty hit list. */
    private function select(int $index): self
    {
        $count = count($this->matches());
        if ($count === 0) {
            return $this;
        }

        return new self($this->rows, $this->query, (($index % $count) + $count) % $count, $this->matches());
    }

    /**
     * The window of rows the body shows: a centered slice around $selected of
     * at most $body rows, as [from, to) — the history search's rule.
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
