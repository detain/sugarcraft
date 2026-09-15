---
paths:
  - candy-palette/src/**
  - candy-core/src/Util/Color.php
---

# ANSI color quantization

- The xterm cube axes are `0, 95, 135, 175, 215, 255` — NOT an even 0-255 spread. Pinned as `Color::CUBE_LEVELS` in `candy-palette/src/Color.php`; an off-ramp quantization shifts every mid-tone by up to 40/255.
- Cube index is `16 + 36R + 6G + B` (16-231); the grey ramp is `232 + i` for levels `8, 18, …, 238`. `toAnsi256Index()` picks whichever of the two candidates is nearer in squared RGB — same rule as `candy-core/src/Util/Color.php`'s `nearest256()`, so the cube/grey overlap is stable monorepo-wide.
- **Never quantize RGB onto slots 0-15.** Those slots are themeable, so truecolor red maps to 196, not to basic slot 1. RGB only ever lands on the fixed part of the palette (16-255).
- `toAnsi16Index()` searches ALL 16 entries (`Color::ANSI16_RGB`) by squared RGB in one pass. Do NOT search the basic 8 and add `+8` on a luminance threshold — that mis-splits the palette (xterm's slot-2 green `(0,205,0)` collapses upward to slot 10) and breaks slot round-tripping.
- `candy-palette`'s `ANSI16_RGB` uses the VGA/common-terminal blues (`#0000CD` / `#0000FF`); `candy-core`'s table uses xterm's `#0000EE` / `#5C5CFF`. The tables diverge for slots 4 and 12 ONLY — intentional. Within `candy-palette` the SAME table must feed decode (`fromAnsi256Index()`), quantize (`toAnsi16Index()`) and render (`toAnsi16()`), so every exact slot value round-trips to itself.
- `fromAnsi256Index()` clamps out-of-range indices (`<0` → 0, `>255` → 255) rather than throwing.
- Degrade fidelity is pinned byte-exactly by `candy-palette/tests/DegradeFidelityTest.php` — it asserts identity rewriting of `38;5;n` / `48;5;n` / `58;5;n` for every n at the ANSI256 profile, plus channel-swap and basic/bright-collapse regressions. Changing any table or rounding rule means updating that matrix.
