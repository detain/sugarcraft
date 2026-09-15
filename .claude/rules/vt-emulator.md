---
paths:
  - candy-vt/src/**
  - candy-vt/tests/**
---

# candy-vt terminal-emulator semantics

- **BCE blank = background ONLY.** `EraseHandler::blankCell()` builds a space carrying just the pen's `background`; foreground and every attribute reset to default (`Sgr::empty()->withBackground(...)`). Copying the whole pen into the blanks is the classic mis-render (VT500 §BCE / xterm "erase colour is the background colour attribute"). Pinned by `candy-vt/tests/Handler/BceBackgroundOnlyTest.php`.
- BCE applies to ED/EL/ECH (`fillRow`) **and** the DCH/ICH shift gaps (`'P'`/`'@'` pass `$sgr` through). The new-row blanks of the IL/DL/SU/SD scrolls (`ScrollHandler`) intentionally stay default cells — a documented divergence.
- BCE is always on for DEC models — there is no `CSI ?12 h/l` gate (that DEC private mode is xterm's reverse-cursor blink).
- **One GENERAL save slot** for `ESC 7`/`ESC 8` and `CSI s`/`CSI u`, exactly as xterm merges DECSC with the SCO extended-cursor save. `ScreenHandler::csiDispatch` routes `s`/`u` to its own `saveCursor()`/`restoreCursor()`; the position half lives on `Cursor::save()`/`restore()`, and `ScreenHandler` completes the snapshot with SGR, the SCS designations, GL invocation and origin mode. `null` saved SGR means "never saved" — DECRC degrades to the position-only no-op rather than conjuring defaults. The legacy `'s'`/`'u'` arms in `CursorHandler::apply()` are unreachable from the wire and kept only for direct callers. Pinned by `candy-vt/tests/Handler/SaveRestoreSlotsTest.php`.
- The DECSC slot is **per screen**: the DEC 1049 alt-screen swap parks/unparks the GENERAL slot's companions instead of restoring them as its own state, and the in-alt save is discarded when the alt screen dies.
- **`CSI 3 J` (ED 3) drains the ring, not the grid.** `ScreenHandler` intercepts it (private `?` marker immaterial, extra params ignored) and calls `Scrollback::clear()` — visible screen and cursor untouched, applied immediately even inside a DEC 2026 window because the ring is history, not screen state. xterm's `eraseSavedLines` resource gate is not modelled. Pinned by `candy-vt/tests/Handler/ScrollbackHygieneTest.php`.
- **Synchronized output (DEC 2026): pass `$pendingMutations` BY REFERENCE** into `EraseHandler::apply()`. Passing it by value made every ED/EL/ECH/DCH/ICH inside a `CSI ?2026h … CSI ?2026l` window append into a throw-away copy and vanish on flush. Pinned by `candy-vt/tests/Handler/SyncOutputEraseTest.php`.
- **DECSTBM homes the cursor** to page home `(0,0)` on a valid region and drops the phantom cell (`wrapPending = false`); an invalid `top > bottom` region takes the early return and leaves the cursor put. Mirrored in `candy-vt/src/Parser/CsiHandlerImpl.php` (renderer models no DECOM). Pinned by `candy-vt/tests/Handler/DecstbmHomeTest.php`.
- `Terminal::resize()` tracks a **full-screen** scroll region across growth: a stale `[0, oldRows-1]` region would become a strict sub-region and permanently silence scrollback for apps that never re-issue DECSTBM.
- **`clone` must be deep for the mutable aggregates.** `ScreenHandler::__clone()` deep-copies `Buffer`, `Scrollback` and the parked alt-screen buffer (immutable value objects stay shared); `Terminal::__clone()` resets the `Parser` to Ground, intentionally dropping any in-flight OSC/DCS payload or partial UTF-8 rune. Pinned by `candy-vt/tests/CloneIsolationTest.php`.
- Doc-comments cite the spec, not the code: `VT500 §<section>` / xterm `ctlseqs`, plus the upstream `charmbracelet/x/vt` line when mirroring it.
