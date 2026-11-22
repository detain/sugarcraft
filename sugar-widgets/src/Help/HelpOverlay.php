<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Help;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Model;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Widgets\Key\KeyMap;
use SugarCraft\Widgets\Key\KeyView;
use SugarCraft\Widgets\Lang;

/**
 * The full key reference as a scrollable TEA model: the rows are
 * {@see Help::fullView()} of the host's key map (generated from a sugar-core
 * {@see KeyBindings} registry when one is given), clipped to {@see $height}
 * rows and scrolled by its own bindings — arrows / `j` `k`, PageUp /
 * PageDown / space, Home / End, and the mouse wheel.
 *
 * Unlike {@see Help} (a pure renderer fed per call), the overlay owns state
 * that changes between frames — the scroll offset and whether it is open —
 * so it IS a {@see Model}: `update(Msg): [HelpOverlay, ?Cmd]`, `view()`.
 * The close binding (Escape / `q` / `?`) closes it and returns a Cmd
 * carrying {@see HelpOverlayClosedMsg} so the host can drop the layer;
 * while closed it ignores every message and renders nothing. Opening it is
 * the host's call ({@see withOpen()}), on whatever key the host binds.
 *
 * Its own navigation keys are a {@see KeyBindings} registry too
 * ({@see defaultKeys()}), so `withKeys(KeyBindings::fromFile(...))` remaps
 * them like any other app binding.
 *
 * Mirrors the charmbracelet/bubbles help.Model full view (ShowAll) composed
 * with viewport.Model scrolling; upstream ships the two separately.
 */
final class HelpOverlay implements Model
{
    private function __construct(
        public readonly KeyMap $keyMap,
        public readonly Help $help,
        public readonly KeyBindings $keys,
        public readonly int $height,
        public readonly int $offset,
        public readonly bool $open,
    ) {
    }

    /**
     * An open overlay over $keyMap, ten rows tall, scrolled to the top.
     */
    public static function new(KeyMap|KeyBindings $keyMap, ?Help $help = null): self
    {
        return new self(
            keyMap: self::keyMap($keyMap),
            help:   $help ?? Help::new(),
            keys:   self::defaultKeys(),
            height: 10,
            offset: 0,
            open:   true,
        );
    }

    /**
     * The overlay's navigation actions: `scroll-up`, `scroll-down`,
     * `page-up`, `page-down`, `top`, `bottom`, `close`.
     */
    public static function defaultKeys(): KeyBindings
    {
        return KeyBindings::fromMap([
            'scroll-up'   => ['up', 'k'],
            'scroll-down' => ['down', 'j'],
            'page-up'     => ['pageup'],
            'page-down'   => ['pagedown', 'space'],
            'top'         => ['home'],
            'bottom'      => ['end'],
            'close'       => ['escape', 'q', '?'],
        ]);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$this->open) {
            return [$this, null];
        }

        if ($msg instanceof MouseMsg) {
            return match ($msg->button) {
                MouseButton::WheelUp   => [$this->scrollBy(-1), null],
                MouseButton::WheelDown => [$this->scrollBy(1), null],
                default                => [$this, null],
            };
        }

        return match ($this->keys->actionFor($msg)) {
            'scroll-up'   => [$this->scrollBy(-1), null],
            'scroll-down' => [$this->scrollBy(1), null],
            'page-up'     => [$this->scrollBy(-$this->height), null],
            'page-down'   => [$this->scrollBy($this->height), null],
            'top'         => [$this->scrollTo(0), null],
            'bottom'      => [$this->scrollTo($this->maxOffset()), null],
            'close'       => [$this->withOpen(false), Cmd::send(new HelpOverlayClosedMsg())],
            default       => [$this, null],
        };
    }

    /**
     * The visible window of the full help: at most {@see $height} rows from
     * {@see $offset}; empty while closed.
     */
    public function view(): string
    {
        if (!$this->open) {
            return '';
        }

        return implode("\n", \array_slice($this->lines(), $this->offset, $this->height));
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /** Replace the key map (offset re-clamped to the new content). */
    public function withKeyMap(KeyMap|KeyBindings $keyMap): self
    {
        return $this->mutate(['keyMap' => self::keyMap($keyMap)]);
    }

    /** Replace the renderer (styles, gaps, width cap). */
    public function withHelp(Help $help): self
    {
        return $this->mutate(['help' => $help]);
    }

    /** Remap the overlay's own navigation (see {@see defaultKeys()}). */
    public function withKeys(KeyBindings $keys): self
    {
        return $this->mutate(['keys' => $keys]);
    }

    /** Visible rows; at least one. */
    public function withHeight(int $rows): self
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException(Lang::t('help_overlay.height_positive'));
        }

        return $this->mutate(['height' => $rows]);
    }

    /** Open or close the overlay; opening keeps the last scroll position. */
    public function withOpen(bool $open): self
    {
        return $this->mutate(['open' => $open]);
    }

    /** Move the window by $rows (negative scrolls up), clamped to the content. */
    public function scrollBy(int $rows): self
    {
        return $this->scrollTo($this->offset + $rows);
    }

    /** Put row $row at the top of the window, clamped to the content. */
    public function scrollTo(int $row): self
    {
        return $this->mutate(['offset' => $row]);
    }

    /** Rows in the full help (before clipping). */
    public function lineCount(): int
    {
        return \count($this->lines());
    }

    /** The largest useful offset: the last row sits on the bottom edge. */
    public function maxOffset(): int
    {
        return max(0, $this->lineCount() - $this->height);
    }

    public function atTop(): bool
    {
        return $this->offset === 0;
    }

    public function atBottom(): bool
    {
        return $this->offset >= $this->maxOffset();
    }

    /** @return list<string> */
    private function lines(): array
    {
        $block = $this->help->withFullSeparator("\n")->fullView($this->keyMap);

        return $block === '' ? [] : explode("\n", $block);
    }

    private static function keyMap(KeyMap|KeyBindings $keyMap): KeyMap
    {
        return $keyMap instanceof KeyBindings ? KeyView::new($keyMap) : $keyMap;
    }

    /**
     * Every state change funnels through here so the offset is re-clamped
     * against whatever the change did to the content or the window.
     *
     * @param array<string,mixed> $changes
     */
    private function mutate(array $changes): self
    {
        $next = new self(...array_merge([
            'keyMap' => $this->keyMap,
            'help'   => $this->help,
            'keys'   => $this->keys,
            'height' => $this->height,
            'offset' => $this->offset,
            'open'   => $this->open,
        ], $changes));
        $clamped = max(0, min($next->offset, $next->maxOffset()));

        return $clamped === $next->offset
            ? $next
            : new self($next->keyMap, $next->help, $next->keys, $next->height, $clamped, $next->open);
    }
}
