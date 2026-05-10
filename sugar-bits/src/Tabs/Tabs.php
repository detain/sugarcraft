<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tabs;

use SugarCraft\Bits\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sprinkles\Style;

/**
 * A tabbed panel component — switch between labelled tabs with keyboard
 * or mouse input.
 *
 * ## Keyboard navigation
 *
 * - `Tab` — advance to the next tab (wraps by default)
 * - `Shift+Tab` — retreat to the previous tab (wraps by default)
 * - `1`–`9` — jump directly to tab N (1-indexed)
 *
 * Wrap-around can be disabled via {@see noWrap()}.
 *
 * ## Rendering
 *
 * Tabs render as a single line: ` Home  │  Profile  │  Settings `.
 * The active tab uses the configured {@see $activeStyle}; inactive tabs
 * use {@see $inactiveStyle}. The divider is rendered in the inactive style.
 *
 * Mirrors charmbracelet/bubbles `Tabs`.
 */
final class Tabs implements Model
{
    /** @param list<string> */
    public readonly array $labels;

    /**
     * @param list<string> $labels
     */
    public function __construct(
        public readonly int $active,
        public readonly Style $activeStyle,
        public readonly Style $inactiveStyle,
        public readonly string $divider,
        public readonly TabsKeyMap $keyMap,
        public readonly bool $focused,
        public readonly bool $wrap,
        public readonly int $width,
        array $labels = [],
    ) {
        $labelCount = count($labels);
        if ($active < 0 || ($labelCount > 0 && $active >= $labelCount)) {
            throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
        }
        $this->labels = array_values($labels);
    }

    /** Bubble-Tea Init — Tabs has no background commands. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @param list<string> $labels Tab labels in display order.
     */
    public static function new(array $labels = [], int $width = 80): self
    {
        return new self(
            active: 0,
            activeStyle: Style::new()->bold(),
            inactiveStyle: Style::new(),
            divider: ' │ ',
            keyMap: TabsKeyMap::default(),
            focused: false,
            wrap: true,
            width: $width,
            labels: $labels,
        );
    }

    /** @return array{0:Model, 1:?\Closure} */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        $count = count($this->labels);
        if ($count === 0) {
            return [$this, null];
        }

        // Tab / Shift+Tab navigation
        if ($this->keyMap->nextTab->matches($msg)) {
            return [
                $this->withActive($this->wrap
                    ? ($this->active + 1) % $count
                    : min($this->active + 1, $count - 1)),
                null,
            ];
        }

        if ($this->keyMap->prevTab->matches($msg)) {
            return [
                $this->withActive($this->wrap
                    ? (($this->active - 1) + $count) % $count
                    : max($this->active - 1, 0)),
                null,
            ];
        }

        // Direct jump: 1-9
        foreach ($this->keyMap->jumpBindings as $i => $binding) {
            if ($binding->matches($msg)) {
                $target = $i; // 0-indexed (jumpBindings[0] = key "1" → tab 0)
                if ($target < $count) {
                    return [$this->withActive($target), null];
                }
            }
        }

        return [$this, null];
    }

    /**
     * Render the tab bar as a single line.
     *
     * Example output with labels `['Home', 'Profile', 'Settings']` and
     * active index 1:
     *
     *     Home  │  Profile  │  Settings
     *     ~~        ^^^^^^        ~~~
     *     inactive   active    inactive
     */
    public function view(): string
    {
        if ($this->labels === []) {
            return '';
        }

        $count = count($this->labels);
        $parts = [];

        foreach ($this->labels as $i => $label) {
            $style = $i === $this->active ? $this->activeStyle : $this->inactiveStyle;
            $parts[] = $style->render(' ' . $label . ' ');
        }

        $line = implode($this->inactiveStyle->render($this->divider), $parts);

        if ($this->width > 0 && mb_strlen($line, 'UTF-8') > $this->width) {
            // Truncate with an ellipsis on the right when the tab bar
            // exceeds the available width.
            $line = mb_strcut($line, 0, $this->width - 1, 'UTF-8') . '…';
        }

        return $line;
    }

    /** Currently active tab index (0-based). */
    public function active(): int
    {
        return $this->active;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->labels;
    }

    // ── with* mutators ───────────────────────────────────────────────────────

    /** @param list<string> */
    public function withLabels(array $labels): self
    {
        $newLabels = array_values($labels);
        $newActive = $this->active;
        if ($newActive >= count($newLabels)) {
            $newActive = max(0, count($newLabels) - 1);
        }
        return new self(
            active: $newActive,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $newLabels,
        );
    }

    public function withActive(int $index): self
    {
        $count = count($this->labels);
        if ($count === 0) {
            return $this;
        }
        $index = max(0, min($index, $count - 1));
        return new self(
            active: $index,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }

    public function withActiveStyle(Style $style): self
    {
        return new self(
            active: $this->active,
            activeStyle: $style,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }

    public function withInactiveStyle(Style $style): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $style,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }

    public function withDivider(string $divider): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }

    public function withKeyMap(TabsKeyMap $keyMap): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }

    public function withWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.neg_width'));
        }
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $width,
            labels: $this->labels,
        );
    }

    /**
     * Disable wrap-around at the ends of the tab list.
     * With wrap disabled, `Tab` clamps at the last tab and
     * `Shift+Tab` clamps at the first tab.
     */
    public function noWrap(): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: false,
            width: $this->width,
            labels: $this->labels,
        );
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [
            new self(
                active: $this->active,
                activeStyle: $this->activeStyle,
                inactiveStyle: $this->inactiveStyle,
                divider: $this->divider,
                keyMap: $this->keyMap,
                focused: true,
                wrap: $this->wrap,
                width: $this->width,
                labels: $this->labels,
            ),
            null,
        ];
    }

    public function blur(): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: false,
            wrap: $this->wrap,
            width: $this->width,
            labels: $this->labels,
        );
    }
}
