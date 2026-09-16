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
- `candy-palette`'s `ANSI16_RGB` and `candy-core`'s table are the SAME xterm-modern table — slots 4/12 are `#0000EE` / `#5C5CFF` (`blue2` and `rgb:5c/5c/ff`, xterm's compiled-in `DEF_COLOR4`/`DEF_COLOR12`). The old `#0000CD` / `#0000FF` blues were never VGA (real VGA is `#0000AA` / `#5555FF`) — they were xterm's own abandoned pre-2009 defaults (X11 `blue3`/`blue`, retired per `XTerm-col.ad`). The two tables were unified in one PR; `candy-palette/tests/Ansi16TableParityTest.php` asserts element-wise equality against `\SugarCraft\Core\Util\Color::ANSI16_RGB` so silent drift is a test failure. Within `candy-palette` the SAME table still feeds decode (`fromAnsi256Index()`), quantize (`toAnsi16Index()`) and render (`toAnsi16()`), so every exact slot value round-trips to itself.
- `fromAnsi256Index()` clamps out-of-range indices (`<0` → 0, `>255` → 255) rather than throwing.
- Degrade fidelity is pinned byte-exactly by `candy-palette/tests/DegradeFidelityTest.php` — it asserts identity rewriting of `38;5;n` / `48;5;n` / `58;5;n` for every n at the ANSI256 profile, plus channel-swap and basic/bright-collapse regressions. Changing any table or rounding rule means updating that matrix.
