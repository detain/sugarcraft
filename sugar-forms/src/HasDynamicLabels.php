<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

use SugarCraft\Forms\Util\RenderSafe;

/**
 * Mixin trait for {@see Field} implementations: provides chainable
 * {@see withTitleFunc()} / {@see withDescriptionFunc()} setters that
 * evaluate at render time, mirroring huh's `*Func` setters.
 *
 * Each closure takes no arguments and returns the dynamic label;
 * callers typically close over a state struct so the rendered title
 * / description tracks live values (e.g. echo a counter back into
 * the field title). When unset, the static `title` / `description`
 * field strings are used.
 *
 * The funcs are preserved across mutations via `clone $this` so a
 * field's `mutate()` helper doesn't need to thread them through.
 *
 * Two sanctioned patterns are deliberate here (E736 plan 3.6/8.7):
 *
 * - `clone` + direct write: like {@see HasHideFunc}, the concrete Field
 *   `mutate()` signatures don't know trait slots; the write lands on the
 *   fresh clone before anything else can observe it, so it behaves
 *   exactly like a `with*()` copy.
 * - `withTitleFunc()` and `withDescriptionFunc()` stay as separate named
 *   setters rather than one `withLabelFunc(string $type, ...)` switch:
 *   the two slots are structurally similar but semantically distinct, and
 *   a type-string parameter would trade intention-revealing API for a
 *   trivial shape dedup.
 */
trait HasDynamicLabels
{
    /** @var ?\Closure(): string */
    private ?\Closure $titleFunc = null;

    /** @var ?\Closure(): string */
    private ?\Closure $descriptionFunc = null;

    /**
     * @param ?\Closure(): string $fn  invoked at render time; null clears
     * @return static
     */
    public function withTitleFunc(?\Closure $fn): static
    {
        $clone = clone $this;
        $clone->titleFunc = $fn;
        return $clone;
    }

    /**
     * @param ?\Closure(): string $fn  invoked at render time; null clears
     * @return static
     */
    public function withDescriptionFunc(?\Closure $fn): static
    {
        $clone = clone $this;
        $clone->descriptionFunc = $fn;
        return $clone;
    }

    /**
     * Resolve the title — closure wins when set, otherwise the static
     * field string. Field bodies should call this from {@see getTitle()}
     * / `view()` instead of reading `$this->title` directly.
     *
     * The result is passed through {@see RenderSafe::clean()} because
     * titles are the single choke point every field renders through, and
     * they may carry attacker-influenced text (both static strings and
     * dynamic `*Func` closures). Cleaning here strips terminal-injection
     * bytes (screen clears, cursor moves, bare ESC) while preserving SGR
     * so a hostile title can't rewrite the surrounding UI.
     */
    public function resolveTitle(string $static): string
    {
        return RenderSafe::clean($this->titleFunc !== null ? (string) ($this->titleFunc)() : $static);
    }

    /**
     * Mirror of {@see resolveTitle()} for the description slot — same
     * {@see RenderSafe::clean()} sanitisation, for the same reason.
     */
    public function resolveDescription(string $static): string
    {
        return RenderSafe::clean($this->descriptionFunc !== null ? (string) ($this->descriptionFunc)() : $static);
    }

    public function hasTitleFunc(): bool       { return $this->titleFunc       !== null; }
    public function hasDescriptionFunc(): bool { return $this->descriptionFunc !== null; }
}