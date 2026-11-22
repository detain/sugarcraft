<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

use SugarCraft\Core\Msg\KeyMsg;

/**
 * Modals stacked in push order: the last pushed is on top, gets every key,
 * and paints over everything beneath it.
 *
 * The stack is the modal guard a host needs instead of one nullable slot per
 * dialog and a priority if-chain to decide which one is live: while the stack
 * is non-empty, keys go to {@see top()} through {@see handleKey()}, and
 * {@see render()} paints every layer bottom to top over the host's frame.
 *
 * Order is push order, not z-index: each layer's veil supplies its look
 * (backdrop dim, border) and its placement, and {@see Veil::zIndex()} is not
 * consulted here (that ordering belongs to {@see VeilStack}).
 *
 * Immutable: push(), pop() and handleKey() return a new stack.
 */
final class ModalStack implements \Countable
{
    /** Same ceiling as VeilStack: render() is one full composite per layer. */
    public const MAX_LAYERS = VeilStack::MAX_LAYERS;

    /** @param list<array{modal: Modal, veil: Veil}> $layers */
    private function __construct(private readonly array $layers = [])
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Push a modal on top.
     *
     * $veil is the layer's look and placement; the default is the modal
     * preset {@see Veil::centered()} spells: a 50% backdrop, CENTER/CENTER.
     *
     * @throws \OverflowException when the stack already holds MAX_LAYERS modals
     */
    public function push(Modal $modal, ?Veil $veil = null): self
    {
        if (\count($this->layers) >= self::MAX_LAYERS) {
            throw new \OverflowException(
                'ModalStack holds at most ' . self::MAX_LAYERS . ' modals; pop one before pushing another',
            );
        }
        $layers = $this->layers;
        $layers[] = ['modal' => $modal, 'veil' => $veil ?? Veil::new()->withBackdrop(50)];

        return new self($layers);
    }

    /** Drop the top modal (a no-op on an empty stack). */
    public function pop(): self
    {
        if ($this->layers === []) {
            return $this;
        }
        $layers = $this->layers;
        \array_pop($layers);

        return new self($layers);
    }

    /** The modal on top, or null when the stack is empty. */
    public function top(): ?Modal
    {
        $last = \array_key_last($this->layers);

        return $last === null ? null : $this->layers[$last]['modal'];
    }

    /**
     * Route a key to the top modal. The modal it returns replaces it; null
     * closes it. An empty stack ignores the key (the host routes it on).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function handleKey(KeyMsg $msg): array
    {
        $last = \array_key_last($this->layers);
        if ($last === null) {
            return [$this, null];
        }

        [$next, $cmd] = $this->layers[$last]['modal']->handleKey($msg);
        $layers = $this->layers;
        if ($next === null) {
            \array_pop($layers);
        } else {
            $layers[$last]['modal'] = $next;
        }

        return [new self($layers), $cmd];
    }

    /**
     * Paint every modal, bottom to top, over $background.
     *
     * Each layer renders its body for the $cols x $rows canvas and is placed
     * with its veil's {@see Veil::placement()} onto the canvas accumulated so
     * far, so an upper modal dims (and covers) the ones beneath it. Like a
     * VeilStack pass, every layer composites a FULL frame (no delta state).
     * An empty stack returns $background untouched.
     */
    public function render(string $background, int $cols, int $rows): string
    {
        $canvas = $background;
        foreach ($this->layers as $layer) {
            $veil = $layer['veil'];
            $canvas = $veil->withoutSession()->place($layer['modal']->render($cols, $rows), $canvas, $veil->placement());
        }

        return $canvas;
    }

    /** @return list<Modal> bottom to top */
    public function all(): array
    {
        return \array_map(static fn (array $layer): Modal => $layer['modal'], $this->layers);
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    public function count(): int
    {
        return \count($this->layers);
    }
}
