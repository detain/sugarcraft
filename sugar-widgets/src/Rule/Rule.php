<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Rule;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Lang;

/**
 * A divider line, horizontal (one row) or vertical (one column), with an
 * optional title on horizontal rules: `── Title ─────────`.
 *
 * Length is in cells (W-35 law: `0` = auto, {@see self::AUTO_LENGTH};
 * negatives throw). The rune must occupy exactly one cell. With no
 * {@see withStyle()} the output carries no SGR at all, so it is safe under
 * `NO_COLOR` and in pipes by construction. Immutable.
 *
 * Antecedent: tview `Divider`/`Flex` separators and the `<hr>` of HTML.
 * A SugarCraft original — upstream charmbracelet/bubbles has no Ruler.
 */
final class Rule
{
    /** Cells a `0` length resolves to. */
    public const AUTO_LENGTH = 40;

    private function __construct(
        public readonly int $length,
        public readonly Orientation $orientation,
        public readonly string $title,
        public readonly ?string $rune,
        public readonly ?Style $style,
    ) {}

    public static function new(int $length = 0): self
    {
        return (new self(0, Orientation::Horizontal, '', null, null))->withLength($length);
    }

    public static function vertical(int $length = 0): self
    {
        return self::new($length)->withOrientation(Orientation::Vertical);
    }

    public function withLength(int $length): self
    {
        if ($length < 0) {
            throw new \InvalidArgumentException(Lang::t('rule.length_nonneg'));
        }
        return $this->mutate(length: $length);
    }

    public function withOrientation(Orientation $o): self
    {
        return $this->mutate(orientation: $o);
    }

    /** Title text; horizontal rules only (a vertical rule ignores it). */
    public function withTitle(string $title): self
    {
        return $this->mutate(title: Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($title)));
    }

    /** Fill rune; must be exactly one cell wide. */
    public function withRune(string $rune): self
    {
        $rune = Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($rune));
        if (Width::string($rune) !== 1) {
            throw new \InvalidArgumentException(Lang::t('rule.bad_rune'));
        }
        return $this->mutate(rune: $rune);
    }

    public function withStyle(?Style $style): self
    {
        return $this->mutate(style: $style, styleSet: true);
    }

    /** Resolved length in cells. */
    public function cells(): int
    {
        return $this->length > 0 ? $this->length : self::AUTO_LENGTH;
    }

    public function view(): string
    {
        $n = $this->cells();
        $vertical = $this->orientation === Orientation::Vertical;
        $rune = $this->rune ?? ($vertical ? '│' : '─');

        if ($vertical) {
            $line = $this->paint($rune);
            return implode("\n", array_fill(0, $n, $line));
        }

        if ($this->title === '') {
            return $this->paint(str_repeat($rune, $n));
        }

        // Two lead runes, a space, the title, a space, then fill; the
        // title clips (never the budget) when the rule is too short.
        $lead = min(2, $n);
        $room = $n - $lead - 2;
        if ($room <= 0) {
            return $this->paint(str_repeat($rune, $n));
        }
        $title = Width::truncate($this->title, $room);
        $used = $lead + 2 + Width::string($title);
        return $this->paint(str_repeat($rune, $lead) . ' ' . $title . ' ' . str_repeat($rune, $n - $used));
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private function paint(string $s): string
    {
        return $this->style === null ? $s : $this->style->render($s);
    }

    private function mutate(
        ?int $length = null,
        ?Orientation $orientation = null,
        ?string $title = null,
        ?string $rune = null,
        ?Style $style = null,
        bool $styleSet = false,
    ): self {
        return new self(
            length: $length ?? $this->length,
            orientation: $orientation ?? $this->orientation,
            title: $title ?? $this->title,
            rune: $rune ?? $this->rune,
            style: $styleSet ? $style : $this->style,
        );
    }
}
