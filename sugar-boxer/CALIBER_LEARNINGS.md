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
