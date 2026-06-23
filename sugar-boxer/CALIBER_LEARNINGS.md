# CALIBER_LEARNINGS — sugar-boxer

## pattern:sprinkles-composition

**sugar-boxer composes candy-sprinkles Border/Style as leaf-node state.**

When adopting canonical styling primitives into a leaf-level library that
does not depend on the full TUI rendering stack, compose the foreign
objects as typed state on the Node rather than subclassing or re-implementing
the primitives.

```php
// Node carries optionalsprinkles types as readonly state
public readonly ?Border $borderStyle;
public readonly ?Style   $style;
public readonly ?Align   $alignH;
public readonly ?VAlign  $alignV;
```

This keeps sugar-boxer decoupled from the rendering internals of
candy-sprinkles while still producing compatible border characters and
style attributes.

## pattern:sentinel-nop

**Private static sentinel to distinguish "do not change" from explicit null.**

The Node `with*` chain uses a private `nop(): \stdClass` sentinel factory
so that `null` can be passed explicitly (to clear a value) without
colliding with "no argument was given, preserve existing".

```php
private static function nop(): \stdClass
{
    static $sentinel;
    return $sentinel ??= new \stdClass();
}

// Usage in with():
$resolvedBorderStyle = $borderStyle === self::nop()
    ? $this->borderStyle           // preserve
    : ($borderStyle ?? $this->borderStyle);  // set or clear
```

This pattern is necessary when chaining multiple `with*` calls that
each forward only their own changed field while passing sentinels for
all others.

## gotcha:border-and-borderstyle

**`withBorder(true)` auto-sets rounded border chars if no borderStyle is
set. `withBorder(false)` does NOT clear borderStyle.**

The default border style is "rounded" for ergonomics. Callers who want
no border AND no implicit style must use `->withBorder(false)->withBorderStyle(null)`.

## gotcha:margin-sugar-boxer-specific

**`withMargin()` is sugar-boxer-specific. candy-sprinkles Style does not
carry margin as a first-class concept.**

Margin is implemented directly on Node rather than delegated to Style,
preserving the Boundary between the layout engine and the styling system.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — buffer-diff-boundaries
**Pattern:** Reset `previousFrame` on resize / cursor-position-lost / first paint. Don't try to diff across these boundaries.
**Anti-pattern:** Retaining a previousFrame across a window resize or cursor-move event produces a delta against a buffer that no longer maps to the physical terminal state — visual corruption.
**Source:** step-27 ai/buffer-diff-consumers

### 2026-06-23 — ansi-aware-content-placement
**Pattern:** Place leaf content one *visible cell* at a time, where a cell = its leading ANSI escape sequences + one grapheme. Group escapes with the grapheme they style (escapes are zero-width and must not consume a column), advance the cursor by the grapheme's visible width (candy-core `Util\Width`), and blank the continuation cell of a wide grapheme. Apply the SAME visible-column measure to any truncation (`splitWord`) — never byte/codepoint offsets (`mb_substr`/`preg_split('//u')`).
**Anti-pattern:** Splitting a line per-codepoint and writing each byte into its own cell — every escape byte (`\e`, `[`, `7`, `m`) steals a column, so styled bodies mis-width and clip, and a clipped reverse-video span loses its reset and bleeds colour into the border / next row.
**Reset safety:** track SGR open/closed across the placed escapes (skip the params of `38/48;5;n` and `38/48;2;r;g;b` colour selectors so a colour index of `0` isn't read as a reset) and emit a trailing reset on the last placed cell whenever a span is left open — by an unbalanced source or by width truncation. A redundant reset is harmless; a missing one bleeds.
**Source:** ai/boxer-ansi-placement
