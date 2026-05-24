# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:per-segment-bg-rect]** `SvgRenderer::render()` emits a `<rect>` for every segment with a non-null `$bg`. The rect is emitted before the `<text>` element at the same X/Y, scaled to `cellW × textLen` wide and `cellH` tall, using the segment's background colour as the fill. This produces per-character background highlighting matching the ANSI terminal experience.

- **[pattern:sgr-bg-48]** ANSI SGR code 48 (set background) is parsed identically to code 38 (set foreground) — mode 5 for 256-color (`\x1b[48;5;Nm`) and mode 2 for 24-bit RGB (`\x1b[48;2;R;G;Bm`). Code 49 resets the background to default. The `AnsiParser::applySgr()` method handles both codes symmetrically.

- **[pattern:language-detector-priority-chain]** `LanguageDetector::detect()` uses a three-tier priority chain: (1) shebang line exact/partial match, (2) filename extension lookup, (3) content signature scoring. Shebang is checked first because it is authoritative when present. Content scoring uses a simple hit-count per language signature array; the language with the most hits wins. Returns `"text"` as the fallback.

- **[pattern:segment-immutable-withbg]** `Segment` is an immutable value object. `withBg(?string $bg)` returns a new `Segment` instance with only the `$bg` field changed (via private constructor + named parameters), leaving `$text`, `$fg`, and attribute flags unchanged. This follows the same `mutate()` pattern used in other SugarCraft immutable classes.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.
