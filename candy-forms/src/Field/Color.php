<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\CarriesNonCtorState;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\HasDynamicLabels;
use SugarCraft\Forms\HasHideFunc;
use SugarCraft\Forms\Lang;

/**
 * xterm-256 color swatch grid (E736 Phase-5 row 5.8, r88-x4). Mirrors the
 * keyboard model of charmbracelet/bubbles' colorpicker: the 6x6x6 color
 * cube, one 6x6 plane of red per page.
 *
 * The cube follows the SAME xterm table semantics candy-vt documents —
 * index `16 + 36r + 6g + b` over component levels [0, 95, 135, 175, 215,
 * 255] — but the values are computed here, not imported: candy-forms does
 * not depend on candy-vt (adding a sibling dep in a lib manifest is a
 * split-repo fatal, so the dependency edge itself was the STOP-gate), and
 * the arithmetic IS the table. Display defaults to `SGR 48;5;index`
 * (exact on any 256-color terminal, since every grid colour is a cube
 * index); {@see withTruecolor()} switches to `48;2;r;g;b` for terminals
 * where a compositor's cube disagrees with xterm's.
 *
 * There is deliberately no validator: the grid cannot produce an
 * unrepresentable value (the hex accessor derives from cube indices, and
 * {@see withValue()} snaps any legal hex string onto the nearest cube
 * corner at the boundary). {@see revalidate()} is therefore a no-op, like
 * {@see Note}'s — the Field interface still demands the slot.
 *
 * Keys while focused (bubbles' axis mapping):
 *   <- / ->          green column   h / l
 *   ^  / v           blue row       k / j   (consumed: the default KeyMap
 *                                            binds them to form nav, the
 *                                            {@see Select} list-mode
 *                                            precedent)
 *   PageUp / PageDown   red plane
 *   Home / End       first / last green column
 *
 * Enter and Tab stay with the Form.
 */
final class Color implements \SugarCraft\Forms\Field
{
    use HasHideFunc;
    use HasDynamicLabels;
    use CarriesNonCtorState;

    /** Component levels of the xterm 6x6x6 cube, index 0..5. */
    private const LEVELS = [0, 95, 135, 175, 215, 255];

    /**
     * @param int $red   cube axis index 0..5 (page)
     * @param int $green cube axis index 0..5 (column)
     * @param int $blue  cube axis index 0..5 (row, top to bottom)
     */
    private function __construct(
        public readonly string $key,
        public readonly int $red,
        public readonly int $green,
        public readonly int $blue,
        public readonly bool $focused,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $truecolor = false,
    ) {
        foreach ([$red, $green, $blue] as $axis) {
            if ($axis < 0 || $axis > 5) {
                // Unreachable through public API — axes are moved by the
                // saturating key handlers and snapped by withValue(). This
                // is the boundary fence for future call sites, not a
                // coercion lane.
                throw new \InvalidArgumentException(Lang::t('color.channel_range'));
            }
        }
    }

    public static function new(string $key, string|null $initial = null): self
    {
        if ($initial === null) {
            return new self($key, 0, 0, 0, false, '', '');
        }
        return self::new($key)->withValue($initial);
    }

    // ------------------------------------------------------------------
    // Fluent knobs
    // ------------------------------------------------------------------

    public function withTitle(string $t): self       { return $this->mutate(title: $t); }
    public function withDescription(string $d): self { return $this->mutate(description: $d); }

    /**
     * Accept `#rrggbb` or bare `rrggbb` (either case); each channel snaps to
     * the nearest cube level, so the picker always displays a colour it can
     * actually represent. The closest level wins ties (e.g. 115 → 95).
     *
     * @throws \InvalidArgumentException on a malformed hex string
     */
    public function withValue(string $hex): self
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) {
            throw new \InvalidArgumentException(Lang::t('color.invalid_hex'));
        }
        [$r, $g, $b] = sscanf($m[1], '%2x%2x%2x');
        return $this->mutate(
            red:   self::nearestLevel((int) $r),
            green: self::nearestLevel((int) $g),
            blue:  self::nearestLevel((int) $b),
        );
    }

    /** Paint swatches with 24-bit `48;2` backgrounds instead of `48;5`. */
    public function withTruecolor(bool $on = true): self
    {
        return $this->mutate(truecolor: $on);
    }

    // Short-form aliases.
    public function title(string $t): self           { return $this->withTitle($t); }
    public function desc(string $d): self            { return $this->withDescription($d); }
    public function truecolor(bool $on = true): self { return $this->withTruecolor($on); }

    // ------------------------------------------------------------------
    // Field contract
    // ------------------------------------------------------------------

    public function key(): string  { return $this->key; }
    public function value(): mixed { return $this->hex(); }

    /** The committed colour, lowercase `#rrggbb` of the current cube corner. */
    public function hex(): string
    {
        return sprintf(
            '#%02x%02x%02x',
            self::LEVELS[$this->red],
            self::LEVELS[$this->green],
            self::LEVELS[$this->blue],
        );
    }

    public function focus(): array { return [$this->mutate(focused: true), null]; }
    public function blur(): Field  { return $this->mutate(focused: false); }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }
        $char = static fn (string $r): bool => $msg->type === KeyType::Char
            && $msg->rune === $r && !$msg->ctrl;

        $next = match (true) {
            $msg->type === KeyType::Left  || $char('h') => $this->mutate(green: $this->saturate($this->green, -1)),
            $msg->type === KeyType::Right || $char('l') => $this->mutate(green: $this->saturate($this->green, +1)),
            $msg->type === KeyType::Up    || $char('k') => $this->mutate(blue:  $this->saturate($this->blue, -1)),
            $msg->type === KeyType::Down  || $char('j') => $this->mutate(blue:  $this->saturate($this->blue, +1)),
            $msg->type === KeyType::PageUp              => $this->mutate(red:   $this->saturate($this->red, +1)),
            $msg->type === KeyType::PageDown            => $this->mutate(red:   $this->saturate($this->red, -1)),
            $msg->type === KeyType::Home => $this->mutate(green: 0),
            $msg->type === KeyType::End  => $this->mutate(green: 5),
            default => null,
        };
        return [$next ?? $this, null];
    }

    /**
     * Title / description, the 6x6 plane (rows = blue, columns = green),
     * then the value line `#rrggbb  R<r> G<g> B<b>`. The cursor cell keeps
     * its background code but adds REVERSE, so it renders as a hollow slot
     * punched out of the painted plane.
     */
    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }

        for ($b = 0; $b <= 5; $b++) {
            $cells = [];
            for ($g = 0; $g <= 5; $g++) {
                $codes = $this->truecolor
                    ? array_merge($b === $this->blue && $g === $this->green ? [Ansi::REVERSE] : [],
                        [48, 2, self::LEVELS[$this->red], self::LEVELS[$g], self::LEVELS[$b]])
                    : array_merge($b === $this->blue && $g === $this->green ? [Ansi::REVERSE] : [],
                        [48, 5, 16 + 36 * $this->red + 6 * $g + $b]);
                $cells[] = Ansi::sgr(...$codes) . '  ' . Ansi::reset();
            }
            $lines[] = implode('', $cells);
        }

        $lines[] = sprintf('%s  R%d G%d B%d', $this->hex(), $this->red, $this->green, $this->blue);
        return implode("\n", $lines);
    }

    public function isFocused(): bool        { return $this->focused; }
    public function getTitle(): string       { return $this->resolveTitle($this->title); }
    public function getDescription(): string { return $this->resolveDescription($this->description); }
    public function getError(): ?string      { return null; }
    public function skippable(): bool        { return false; }

    /** Claims only what the default KeyMap would otherwise steal (see {@see Select}). */
    public function consumes(Msg $msg): bool
    {
        if (!$this->focused || !$msg instanceof KeyMsg) {
            return false;
        }
        return match ($msg->type) {
            KeyType::Up, KeyType::Down => true,
            default => false,
        };
    }

    /**
     * @internal No validator can exist on this field — see class docblock.
     */
    public function revalidate(): Field { return $this; }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Cube index of the level nearest to a 0..255 byte. */
    private static function nearestLevel(int $byte): int
    {
        $best = 0;
        $bestDistance = PHP_INT_MAX;
        foreach (self::LEVELS as $i => $level) {
            $distance = abs($byte - $level);
            if ($distance < $bestDistance) {
                $best = $i;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Lattice move that saturates at the cube edges instead of wrapping. */
    private function saturate(int $axis, int $delta): int
    {
        $next = $axis + $delta;
        if ($next < 0) {
            return 0;
        }
        if ($next > 5) {
            return 5;
        }
        return $next;
    }

    /**
     * Hand-written immutable copy; every axis re-passes the constructor
     * boundary fence, and only the label-trait closures ride outside (E741).
     */
    private function mutate(
        ?int $red = null,
        ?int $green = null,
        ?int $blue = null,
        ?bool $focused = null,
        ?string $title = null,
        ?string $description = null,
        ?bool $truecolor = null,
    ): self {
        return $this->carryNonCtorState(new self(
            key:         $this->key,
            red:         $red       ?? $this->red,
            green:       $green     ?? $this->green,
            blue:        $blue      ?? $this->blue,
            focused:     $focused   ?? $this->focused,
            title:       $title     ?? $this->title,
            description: $description ?? $this->description,
            truecolor:   $truecolor ?? $this->truecolor,
        ));
    }
}
