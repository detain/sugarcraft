<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Style\Style;

/**
 * The project-trust dialog component (C-SEC-5.c): one row per pending
 * {@see TrustTarget} of a project root, answered `y`/`n` row by row, `a` for
 * all of them, or dismissed with `Esc`.
 *
 * WIRED SINCE C-SEC-5.d. {@see \SugarCraft\Code\Cli\LaunchContext} seeds
 * this dialog into the TUI app at launch for every project with pending
 * census rows, answered before the first turn. The component stays
 * deliberately inert, exactly as the {@see
 * \SugarCraft\Code\Config\Trust\ProjectTrustCensus} it consumes was.
 *
 * IT ONLY COMPUTES. Every answer is carried out as a value: the dialog never
 * writes a settings list, never touches {@see \SugarCraft\Code\MCP\McpTrustPins},
 * and never imports sugar-async. {@see choices()} is the whole output, and the
 * host that wires 5.d decides what to do with it. The same discipline keeps
 * `view()` a pure function of `(cols, rows, Theme)`, so the step needs no
 * Renderer edit — the modal paints its own box and the shell centres it.
 *
 * THE ROWS NAME WHAT GRANTING ENABLES, not just what the key is. The four
 * `trustedProject*` lists are opaque strings at the settings surface; at the
 * moment of decision a user deserves to read the consequence — hooks run
 * programs, MCP starts servers, the `!` command form fires, the project's
 * settings layer may change N keys. The counts are supplied at open time, so
 * this class performs no filesystem I/O either.
 *
 * THE FROZEN-ROSTER LINE. Every grant answers into the USER config and is read
 * once per launch ({@see \SugarCraft\Code\Cli\Bootstrap}'s trust memos), so a
 * grant taken here is only reported true from the NEXT launch. The line is
 * pinned verbatim because it is the sentence the whole dialog exists to make
 * unmissable.
 */
final class TrustDialog
{
    /** Box width the dialog never grows past: it is a popup, not a band. */
    public const MAX_COLS = 72;

    /** Below this box width the copy collapses toward truncation; the frame
     * still draws and no line ever exceeds `$cols` (the TUI render invariant). */
    public const MIN_COLS = 14;

    private function __construct(
        private readonly string $root,
        /** @var list<TrustTarget> the pending targets, in census order */
        private readonly array $targets,
        private readonly int $settingsKeyCount,
        /** Index into {@see $targets} of the row being asked. */
        private readonly int $cursor,
        /** @var list<TrustTarget> */
        private readonly array $granted,
        /** @var list<TrustTarget> */
        private readonly array $skipped,
        private readonly bool $dismissed,
    ) {
    }

    /**
     * Open the dialog over the pending census output for `$root`.
     *
     * `$settingsKeyCount` is the number of settings keys the project's layer
     * could change — supplied by the caller (the census knows it from the
     * declared files); it is only ever rendered on the Settings row.
     *
     * @param list<TrustTarget> $targets
     */
    public static function new(string $root, array $targets, int $settingsKeyCount = 0): self
    {
        return new self(
            root: $root,
            targets: array_values($targets),
            settingsKeyCount: max(0, $settingsKeyCount),
            cursor: 0,
            granted: [],
            skipped: [],
            dismissed: false,
        );
    }

    // ── state ───────────────────────────────────────────────────────────

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<TrustTarget> */
    public function targets(): array
    {
        return $this->targets;
    }

    /** The row being asked; null once every row has an answer. */
    public function current(): ?TrustTarget
    {
        if ($this->dismissed || $this->cursor >= \count($this->targets)) {
            return null;
        }

        return $this->targets[$this->cursor];
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** @return list<TrustTarget> rows answered `y` (empty after a dismissal) */
    public function granted(): array
    {
        return $this->dismissed ? [] : $this->granted;
    }

    /** @return list<TrustTarget> rows answered `n` (empty after a dismissal) */
    public function skipped(): array
    {
        return $this->dismissed ? [] : $this->skipped;
    }

    /** Every row has an answer and the dialog is ready to close. */
    public function isComplete(): bool
    {
        return !$this->dismissed && $this->cursor >= \count($this->targets);
    }

    /** `Esc` was pressed: the dialog is closed and NOTHING is answered. */
    public function isDismissed(): bool
    {
        return $this->dismissed;
    }

    /** The dialog no longer takes keys: complete or dismissed. */
    public function isResolved(): bool
    {
        return $this->dismissed || $this->cursor >= \count($this->targets);
    }

    /**
     * The answers as data, in census order — the component's entire output.
     * A dismissal returns the empty list: dismissing the dialog is not
     * answering it.
     *
     * @return list<array{target: TrustTarget, granted: bool}>
     */
    public function choices(): array
    {
        if ($this->dismissed || !$this->isComplete()) {
            return [];
        }

        $choices = [];
        foreach ($this->targets as $target) {
            $choices[] = ['target' => $target, 'granted' => \in_array($target, $this->granted, true)];
        }

        return $choices;
    }

    // ── keys ────────────────────────────────────────────────────────────

    /**
     * `y` grants the highlighted row, `n` skips it, `a` grants every
     * remaining row at once, `Esc` dismisses the dialog. Any other key —
     * or any key after resolution — leaves the model untouched.
     */
    public function update(KeyMsg $msg): self
    {
        if ($this->isResolved()) {
            return $this;
        }

        if ($msg->type === KeyType::Escape) {
            return $this->mutate(dismissed: true);
        }

        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt) {
            return $this;
        }

        return match ($msg->rune) {
            'y' => $this->answerCurrent(granted: true),
            'n' => $this->answerCurrent(granted: false),
            'a' => $this->grantAllRemaining(),
            default => $this,
        };
    }

    // ── answers ─────────────────────────────────────────────────────────

    private function answerCurrent(bool $granted): self
    {
        $target = $this->current();

        if ($target === null) {
            return $this;
        }

        return $this->mutate(
            cursor: $this->cursor + 1,
            granted: $granted ? [...$this->granted, $target] : $this->granted,
            skipped: $granted ? $this->skipped : [...$this->skipped, $target],
        );
    }

    /** `a`: every unanswered row becomes a grant; already-skipped rows keep their answer. */
    private function grantAllRemaining(): self
    {
        $granted = $this->granted;
        foreach (\array_slice($this->targets, $this->cursor) as $target) {
            $granted[] = $target;
        }

        return $this->mutate(cursor: \count($this->targets), granted: $granted);
    }

    /**
     * A copy with named fields replaced (house immutable law); anything not
     * named carries over verbatim.
     */
    private function mutate(
        ?int $cursor = null,
        ?array $granted = null,
        ?array $skipped = null,
        ?bool $dismissed = null,
    ): self {
        return new self(
            root: $this->root,
            targets: $this->targets,
            settingsKeyCount: $this->settingsKeyCount,
            cursor: $cursor ?? $this->cursor,
            granted: $granted ?? $this->granted,
            skipped: $skipped ?? $this->skipped,
            dismissed: $dismissed ?? $this->dismissed,
        );
    }

    // ── rendering ───────────────────────────────────────────────────────

    /**
     * The dialog box, `$rows` lines tall, every line at most `$cols` cells
     * wide (the TUI render invariant: content is truncated, never wrapped
     * past the edge).
     *
     * The box sizes itself to its content up to {@see MAX_COLS}, so one short
     * row never drags a wide frame across the screen; inside it every row is
     * `Width::truncate`d to the exact interior and padded to the exact box.
     */
    public function view(int $cols, int $rows, Theme $theme): string
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);

        if ($cols < 6) {
            // No room for chrome at all: the invariant (no line over `$cols`)
            // outranks the frame, so the copy is offered as plain lines.
            $plain = [Lang::t('tui.trust.title'), $this->root];
            foreach ($this->targets as $index => $target) {
                $plain[] = $this->rowText($index, $target);
            }
            $plain[] = Lang::t('tui.trust.effect');

            $lines = array_map(
                static fn (string $t): string => str_pad(Width::truncate($t, $cols), $cols),
                $plain,
            );
            while (\count($lines) < $rows) {
                $lines[] = str_repeat(' ', $cols);
            }

            return implode("\n", array_map(
                static fn (string $l): string => rtrim($l, ' '),
                \array_slice($lines, 0, $rows),
            ));
        }

        $box = min($cols, max(self::MIN_COLS, min(self::MAX_COLS, $this->naturalWidth($theme))));
        $inner = max(1, $box - 4);

        $border = Style::new()->foreground($theme->shellPrimary);
        $muted = Style::new()->foreground($theme->shellMuted);

        $lines = [$this->topBorder(' ' . Lang::t('tui.trust.title') . ' ', $box, $border)];

        if ($this->root !== '') {
            $lines[] = $this->sided($muted->render(Width::truncate($this->root, $inner)), $inner, $border);
        }

        $lines[] = $this->sided($muted->render(str_repeat('─', $inner)), $inner, $border);

        foreach ($this->targets as $index => $target) {
            $lines[] = $this->sided($this->rowLine($index, $target, $inner, $theme), $inner, $border);
        }

        $lines[] = $this->sided($muted->render(str_repeat('─', $inner)), $inner, $border);
        $lines[] = $this->sided(
            $muted->render(Width::truncate(Lang::t('tui.trust.effect'), $inner)),
            $inner,
            $border,
        );
        $lines[] = $this->sided(
            $muted->render(Width::truncate(Lang::t('tui.trust.keys'), $inner)),
            $inner,
            $border,
        );
        $lines[] = $this->bottomBorder($box, $border);

        // Height budget: the chrome (top, root, rules, effect, keys, bottom)
        // is kept before the target rows are squeezed — an unanswered row is
        // recoverable, an open box is not. Under six lines even the chrome
        // cannot fit and the plain final slice drops the bottom-most lines.
        $chrome = 7 - ($this->root === '' ? 1 : 0);
        if (\count($lines) > $rows) {
            $keep = max(0, $rows - $chrome);
            $lines = [
                ...\array_slice($lines, 0, $chrome - 4),
                ...\array_slice($lines, $chrome - 4, $keep),
                ...\array_slice($lines, -4),
            ];
        }

        while (\count($lines) < $rows) {
            $lines[] = '';
        }
        $lines = \array_slice($lines, 0, $rows);

        return implode("\n", array_map(
            static fn (string $line): string => rtrim($line, ' '),
            $lines,
        ));
    }

    /**
     * The widest the content wants: the longest un-truncated row line, the
     * two fixed copy lines and the title, plus the box's own chrome. Clamped
     * by the caller into `[MIN_COLS, min(cols, MAX_COLS)]`.
     */
    private function naturalWidth(Theme $theme): int
    {
        $needed = max(
            Width::of(' ' . Lang::t('tui.trust.title') . ' ') + 4,
            Width::of(Lang::t('tui.trust.effect')) + 4,
            Width::of(Lang::t('tui.trust.keys')) + 4,
        );

        foreach ($this->targets as $index => $target) {
            $needed = max($needed, Width::of($this->rowText($index, $target)) + 4);
        }

        if ($this->root !== '') {
            $needed = max($needed, Width::of($this->root) + 4);
        }

        return $needed;
    }

    /** @return string the row's plain text (no SGR), for measurement and paint alike */
    private function rowText(int $index, TrustTarget $target): string
    {
        $glyph = match (true) {
            $this->dismissed => ' ',
            \in_array($target, $this->granted, true) => '✓',
            \in_array($target, $this->skipped, true) => '·',
            $index === $this->cursor => '›',
            default => ' ',
        };

        // The key rows are spelled out as literals, not built from
        // `$target->value`: the `tui.*` census in
        // {@see \SugarCraft\Code\Tests\Tui\TuiLangTest} walks quoted strings,
        // so a concatenated key would strand its catalogue entry as "dead"
        // while still resolving at runtime. The exhaustive `match` is also
        // 5.a's own no-drift idiom: a fifth grant cannot slip past a row.
        $label = match ($target) {
            TrustTarget::Mcp => Lang::t('tui.trust.target.mcp'),
            TrustTarget::McpPin => Lang::t('tui.trust.target.mcp_pin'),
            TrustTarget::Hooks => Lang::t('tui.trust.target.hooks'),
            TrustTarget::Commands => Lang::t('tui.trust.target.commands'),
            TrustTarget::Settings => Lang::t('tui.trust.target.settings'),
        };
        $enable = match ($target) {
            TrustTarget::Mcp => Lang::t('tui.trust.enable.mcp'),
            TrustTarget::McpPin => Lang::t('tui.trust.enable.mcp_pin'),
            TrustTarget::Hooks => Lang::t('tui.trust.enable.hooks'),
            TrustTarget::Commands => Lang::t('tui.trust.enable.commands'),
            TrustTarget::Settings => Lang::t('tui.trust.enable.settings', ['count' => $this->settingsKeyCount]),
        };

        return $glyph . ' ' . $label . ' — ' . $enable;
    }

    private function rowLine(int $index, TrustTarget $target, int $inner, Theme $theme): string
    {
        $text = $this->rowText($index, $target);

        // Answered grants carry success, answered skips and a dismissed
        // dialog carry muted, the row being asked carries the warning-bold
        // cursor; rows still ahead of the cursor stay unstyled — they are
        // neither won nor lost yet.
        $style = match (true) {
            $this->dismissed => Style::new()->foreground($theme->shellMuted),
            $index < $this->cursor => \in_array($target, $this->granted, true)
                ? Style::new()->foreground($theme->shellSuccess)
                : Style::new()->foreground($theme->shellMuted),
            $index === $this->cursor => Style::new()->foreground($theme->shellWarning)->bold(),
            default => Style::new(),
        };

        return $style->render(Width::truncate($text, $inner));
    }

    /** Top frame: `╭─ title ──…──╮`, exactly $box cells. */
    private function topBorder(string $title, int $box, Style $border): string
    {
        $title = Width::truncate($title, max(0, $box - 4));
        $fill = max(0, $box - 4 - Width::of($title));

        return $border->render('╭─' . $title . str_repeat('─', $fill) . '─╮');
    }

    /** Bottom frame: `╰───…───╯`, exactly $box cells. */
    private function bottomBorder(int $box, Style $border): string
    {
        return $border->render('╰' . str_repeat('─', max(0, $box - 2)) . '╯');
    }

    /** `│ ` + interior padded to $inner + ` │`; exactly $box cells. */
    private function sided(string $line, int $inner, Style $border): string
    {
        $pad = max(0, $inner - Width::of($line));

        return $border->render('│') . ' ' . $line . str_repeat(' ', $pad) . ' ' . $border->render('│');
    }
}
