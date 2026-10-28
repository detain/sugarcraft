<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\DiffReview;

use SugarCraft\Code\Lang;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

use function count;
use function max;
use function min;

/**
 * The keyboard-owned state of the diff review overlay (C-TUI-2.d): the
 * per-file rows of one checkpoint-to-checkpoint diff, which file is selected,
 * how far back the base checkpoint is, and the body scroll.
 *
 * Mirrors {@see \SugarCraft\Code\Tui\HistorySearch()} in shape — immutable, a
 * private constructor, {@see update()} answering [self, ?action] — except the
 * action is the typed {@see DiffReviewAction} the wiring step turns into a
 * reload or a close, not a name. Motion rides the house
 * {@see Mutable} mutate() carrier, the same private-mutate shape
 * sugar-style/src/Style.php sets. All the text arrives already screen-safe
 * from {@see \SugarCraft\Code\Workspace\CheckpointDiff::files()}; this class
 * never re-reads git and never re-scrubs.
 *
 * Motion clamps instead of wrapping: on a file list a wrapped cursor reads as
 * a jump back to the top of the changes, and on the base stepper the bounds
 * ARE the history — there is no checkpoint before the first turn to wrap to.
 * Selecting another file, stepping the base, and flipping the turn view all
 * reset the body scroll, because a scroll remembered across a different patch
 * is meaningless at best and misleading at worst.
 */
final class DiffReview
{
    use Mutable;

    /** Lines a page turn moves the body. */
    public const PAGE_LINES = 20;

    /**
     * @param list<array{status: string, path: string, added: int, removed: int, patch: string}> $files
     */
    private function __construct(
        private readonly array $files,
        private readonly int $selected,
        private readonly int $stepsBack,
        private readonly int $bases,
        private readonly bool $turnOnly,
        private readonly int $scroll,
        private readonly int $omittedLines,
    ) {}

    /**
     * The resting state for a diff: first file, body at the top, $stepsBack
     * normalised into the range the bases actually allow.
     */
    public static function open(array $files, int $stepsBack, int $bases, bool $turnOnly, int $omittedLines): self
    {
        return new self(
            $files,
            0,
            min(max(1, $stepsBack), self::bound($bases, $turnOnly)),
            max(0, $bases),
            $turnOnly,
            0,
            max(0, $omittedLines),
        );
    }

    /**
     * The furthest base back: every checkpoint in the ordinary view, one less
     * in the turn view (a turn needs both sides), and never below one.
     */
    private static function bound(int $bases, bool $turnOnly): int
    {
        return max(1, $turnOnly ? $bases - 1 : $bases);
    }

    /**
     * @return array{0: self, 1: DiffReviewAction|null}
     */
    public function update(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Escape => [$this, DiffReviewAction::close()],
            $msg->type === KeyType::Down,
            $msg->type === KeyType::Char && $msg->rune === 'j' && !$msg->ctrl && !$msg->alt => $this->selectRelative(1),
            $msg->type === KeyType::Up,
            $msg->type === KeyType::Char && $msg->rune === 'k' && !$msg->ctrl && !$msg->alt => $this->selectRelative(-1),
            $msg->type === KeyType::PageDown => $this->scrollBody(self::PAGE_LINES),
            $msg->type === KeyType::PageUp => $this->scrollBody(-self::PAGE_LINES),
            $msg->type === KeyType::Home => $this->scrollTop(),
            $msg->type === KeyType::End => $this->scrollBottom(),
            $msg->type === KeyType::Char && $msg->rune === '-' && !$msg->ctrl && !$msg->alt => $this->stepBase(1),
            $msg->type === KeyType::Char && ($msg->rune === '+' || $msg->rune === '=') && !$msg->ctrl && !$msg->alt => $this->stepBase(-1),
            $msg->type === KeyType::Char && $msg->rune === 't' && !$msg->ctrl && !$msg->alt => $this->toggleTurn(),
            default => [$this, null],
        };
    }

    /**
     * @return array{0: self, 1: null}
     */
    private function selectRelative(int $delta): array
    {
        $last = max(0, count($this->files) - 1);
        $next = min(max(0, $this->selected + $delta), $last);
        if ($next === $this->selected) {
            return [$this, null];
        }

        return [$this->mutate(['selected' => $next, 'scroll' => 0]), null];
    }

    /**
     * @return array{0: self, 1: null}
     */
    private function scrollBody(int $delta): array
    {
        $next = min(max(0, $this->scroll + $delta), $this->lastScroll());
        if ($next === $this->scroll) {
            return [$this, null];
        }

        return [$this->mutate(['scroll' => $next]), null];
    }

    /**
     * @return array{0: self, 1: null}
     */
    private function scrollTop(): array
    {
        return $this->scroll === 0 ? [$this, null] : [$this->mutate(['scroll' => 0]), null];
    }

    /**
     * @return array{0: self, 1: null}
     */
    private function scrollBottom(): array
    {
        $last = $this->lastScroll();

        return $this->scroll === $last ? [$this, null] : [$this->mutate(['scroll' => $last]), null];
    }

    private function lastScroll(): int
    {
        return max(0, count($this->bodyLines()) - 1);
    }

    /**
     * Move the base checkpoint by $delta (positive walks further back). The
     * patch is not carried here — the caller reloads it from the store, which
     * is why a real move returns a {@see DiffReviewAction::reload()}.
     *
     * @return array{0: self, 1: DiffReviewAction|null}
     */
    private function stepBase(int $delta): array
    {
        if ($this->bases === 0) {
            return [$this, null];
        }
        $next = min(max(1, $this->stepsBack + $delta), self::bound($this->bases, $this->turnOnly));
        if ($next === $this->stepsBack) {
            return [$this, null];
        }

        return [
            $this->mutate(['stepsBack' => $next, 'selected' => 0, 'scroll' => 0]),
            DiffReviewAction::reload($next, $this->turnOnly),
        ];
    }

    /**
     * Flip between all-checkpoints and this-turn-only. Turning ON with fewer
     * than two bases is refused — there would be no pair to diff — and a
     * flip that succeeds always reloads, because the two views come from
     * different sources even when the number lands on the same base.
     *
     * @return array{0: self, 1: DiffReviewAction|null}
     */
    private function toggleTurn(): array
    {
        if (!$this->turnOnly && $this->bases < 2) {
            return [$this, null];
        }
        $on = !$this->turnOnly;
        $next = min($this->stepsBack, self::bound($this->bases, $on));

        return [
            $this->mutate(['turnOnly' => $on, 'stepsBack' => $next, 'selected' => 0, 'scroll' => 0]),
            DiffReviewAction::reload($next, $on),
        ];
    }

    /**
     * @return list<array{status: string, path: string, added: int, removed: int, patch: string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return list<string> one label per file, `M path +1 -2` style
     */
    public function fileLabels(): array
    {
        return array_map(
            static fn (array $file): string => $file['status'] . ' ' . $file['path']
                . ' +' . $file['added'] . ' -' . $file['removed'],
            $this->files,
        );
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    /**
     * @return array{status: string, path: string, added: int, removed: int, patch: string}|null
     */
    public function selectedFile(): ?array
    {
        return $this->files[$this->selected] ?? null;
    }

    /**
     * The selected file's patch, split into screen-safe rows.
     *
     * @return list<string>
     */
    public function bodyLines(): array
    {
        $patch = $this->selectedFile()['patch'] ?? '';

        return $patch === '' ? [] : explode("\n", $patch);
    }

    public function scroll(): int
    {
        return $this->scroll;
    }

    public function stepsBack(): int
    {
        return $this->stepsBack;
    }

    public function bases(): int
    {
        return $this->bases;
    }

    public function turnOnly(): bool
    {
        return $this->turnOnly;
    }

    public function omittedLines(): int
    {
        return $this->omittedLines;
    }

    public function title(): string
    {
        return Lang::t('tui.diffreview.title', ['steps' => $this->stepsBack])
            . ($this->turnOnly ? Lang::t('tui.diffreview.turn_suffix') : '');
    }

    public function emptyNotice(): string
    {
        return Lang::t('tui.diffreview.empty');
    }

    public function omittedNotice(): string
    {
        return $this->omittedLines === 0
            ? ''
            : Lang::t('tui.diffreview.omitted', ['lines' => $this->omittedLines]);
    }

    public function hints(): string
    {
        return Lang::t('tui.diffreview.hints');
    }
}
