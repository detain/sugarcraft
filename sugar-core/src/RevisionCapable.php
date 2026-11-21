<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Optional revision key for {@see Model}s whose view() can be memoized.
 *
 * PHP interfaces cannot carry a default body, and {@see Model} has many
 * implementers, so the revision is an opt-in companion interface rather
 * than a method on Model itself: a model without it behaves exactly as
 * before. {@see ScreenStackCapable} is the same shape.
 *
 * {@see Program} asks for the revision on every frame. When it equals the
 * revision the previous frame was rendered under (and the active model is
 * the same class), view() is not called and the previous rendered value is
 * painted again. Return null for "no revision": view() then runs on every
 * frame. A revision must change whenever the output of view() would change,
 * including with the terminal size if the view depends on it.
 */
interface RevisionCapable
{
    /**
     * Return the current view revision, or null to opt out for this frame.
     */
    public function revision(): int|string|null;
}
