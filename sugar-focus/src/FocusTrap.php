<?php

declare(strict_types=1);

namespace SugarCraft\Focus;

/**
 * An immutable modal focus trap over a {@see FocusRing}: opening it captures
 * what the dialog interrupts (the focused id and every enabled id) and
 * disables the background; {@see release()} undoes it in the one correct order
 * with no argument, because the trap holds its own base state.
 *
 *     $trap = FocusTrap::new($ring)->guarding('dialog-ok', 'dialog-cancel');
 *     $trap = $trap->withRing($trap->ring()->next());   // Tab inside the dialog
 *     $ring = $trap->release();                         // background back, focus restored
 *
 * Release order: re-enable background, unregister the dialog ids, then restore
 * focus — to the saved id when it is still enabled, otherwise the first enabled
 * region (covers a ring that was empty, parked on a disabled region, or lost
 * the saved id while the dialog was open). Every with*() returns a new
 * instance via {@see mutate()}.
 */
final class FocusTrap
{
    /**
     * @param list<string> $background ids that were enabled when the trap opened
     * @param list<string> $dialog     ids the trap registered
     */
    private function __construct(
        private readonly FocusRing $ring,
        private readonly ?string $saved,
        private readonly array $background,
        private readonly array $dialog = [],
    ) {
    }

    /** Open a trap over $base: every currently enabled region becomes unreachable. */
    public static function new(FocusRing $base): self
    {
        $saved = $base->current();
        $background = $base->enabledIds();
        $ring = $base;
        foreach ($background as $id) {
            $ring = $ring->disable($id);
        }

        return new self($ring, $saved, $background);
    }

    /**
     * Register the dialog's focusable regions and focus the first.
     *
     * @throws \InvalidArgumentException when an id is already registered (it would be background)
     */
    public function guarding(string ...$ids): self
    {
        $ring = $this->ring;
        $dialog = $this->dialog;
        foreach ($ids as $id) {
            if ($ring->has($id)) {
                throw new \InvalidArgumentException("FocusTrap: region \"{$id}\" is already registered.");
            }
            $ring = $ring->register($id);
            $dialog[] = $id;
        }
        if ($ids !== []) {
            $ring = $ring->focus($ids[0]);
        }

        return $this->mutate(ring: $ring, dialog: $dialog);
    }

    /** Replace the trapped ring, e.g. after Tab / Shift-Tab inside the dialog. */
    public function withRing(FocusRing $ring): self
    {
        return $this->mutate(ring: $ring);
    }

    /** The ring as it stands while trapped (background disabled, dialog ids live). */
    public function ring(): FocusRing
    {
        return $this->ring;
    }

    /** The id focused when the trap opened, or null on an empty ring. */
    public function saved(): ?string
    {
        return $this->saved;
    }

    /** @return list<string> the background ids the trap holds disabled */
    public function background(): array
    {
        return $this->background;
    }

    /** @return list<string> the dialog ids the trap registered */
    public function dialog(): array
    {
        return $this->dialog;
    }

    /** Close the dialog: re-enable background, drop dialog ids, restore focus. */
    public function release(): FocusRing
    {
        $ring = $this->ring;
        foreach ($this->background as $id) {
            $ring = $ring->enable($id);
        }
        foreach ($this->dialog as $id) {
            $ring = $ring->unregister($id);
        }

        return $this->saved !== null && $ring->isEnabled($this->saved)
            ? $ring->focus($this->saved)
            : $ring->focusFirst();
    }

    /** @param list<string>|null $dialog */
    private function mutate(?FocusRing $ring = null, ?array $dialog = null): self
    {
        return new self(
            $ring ?? $this->ring,
            $this->saved,
            $this->background,
            $dialog ?? $this->dialog,
        );
    }
}
