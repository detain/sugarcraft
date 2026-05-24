# sugar-stickers CALIBER_LEARNINGS

## [pattern:ssot-composition] — SSOT composition via wrapper classes

Viewport and Scrollbar are composed from sugar-bits rather than reimplemented.
The sugar-stickers namespace (`SugarCraft\Stickers`) wraps canonical
`SugarCraft\Bits` types as immutable value objects. This keeps sticker-level
customisation options open without duplicating scroll/viewport logic.

- Viewport: `SugarCraft\Stickers\Viewport` wraps `SugarCraft\Bits\Viewport\Viewport`
- Scrollbar: `SugarCraft\Stickers\Scrollbar` wraps `SugarCraft\Bits\Scrollbar\Scrollbar`

Sticky header/footer positioning and scroll-sync are deferred to step 10.12.

## [pattern:sticky-positioning-deferred] — sticky headers/footers deferred to step 10.12

Viewport sticky positioning (sticky headers/footers that appear/disappear
as the user scrolls) is out of scope for the SSOT composition step and is
deferred to step 10.12. The Viewport wrapper currently delegates all rendering
to the sugar-bits Viewport.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
