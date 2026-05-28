# Step 05 — Create candy-mouse

**Branch:** `ai/candy-mouse-new`
**Depends on:** step-00 ✅
**Blocks:** step-14 (sugar-bits uses it), step-22 (sugar-veil/sugar-crumbs/candy-lister consume it)

## Goal

Create the `candy-mouse` foundation package — self-contained Mark/Scan/Get mouse hit-testing (bubblezone pattern) plus `ZoneClickTracker` for press/release dedup. Replaces the current model where consumers wire candy-zone's `Manager` externally; each consumer becomes responsible for its own zones with no external coordination.

Reference: `docs/repo_map_update.md` §327.6 (framework), §345.5 (ZoneClickTracker), §369.4 (consolidation), §387.4 (reinvention of manual coordinate tracking).

## Files expected to be created

- `candy-mouse/composer.json`
- `candy-mouse/phpunit.xml`
- `candy-mouse/README.md`
- `candy-mouse/CALIBER_LEARNINGS.md`
- `candy-mouse/src/Mark.php` — wrap rendered string with invisible OSC zone markers.
- `candy-mouse/src/Scan.php` — extract zones from a marked string; compute bounding boxes.
- `candy-mouse/src/Zone.php` — readonly value object (id, region).
- `candy-mouse/src/Scanner.php` — stateful scan-then-get fluent API.
- `candy-mouse/src/ZoneClickTracker.php` — dedup MouseDown/Up pairs per zone.
- `candy-mouse/src/MouseEvent.php` — readonly (x, y, button, action: enum Press/Release/Drag/Scroll).
- `candy-mouse/tests/MarkTest.php`, `ScannerTest.php`, `ZoneClickTrackerTest.php`.

## Files expected to be modified

- Root composer.json, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/candy-mouse.html, codecov.yml.

## Acceptance criteria

- [ ] `Mark::wrap(string $id, string $content): string` — wraps content with sentinel sequences (use private-use Unicode codepoints to avoid clobbering ANSI).
- [ ] `Scanner::new()->scan(string $rendered): self` — parses sentinels, returns Scanner with internal zone registry.
- [ ] `Scanner::get(string $id): ?Zone` — returns Zone with its bounding region in (col, row) terms.
- [ ] `Scanner::hit(int $col, int $row): ?Zone` — reverse lookup; O(log n) preferred (spatial index — but a flat list is fine if n < 100).
- [ ] `ZoneClickTracker::track(MouseEvent): ?ClickResult` — returns a single Click per Press+Release pair on the same zone; suppresses duplicate Press events fired during drag.
- [ ] **No deps on candy-zone** — candy-mouse is self-contained. (candy-zone keeps its existing Manager API for back-compat; consumers migrate in step-22.)
- [ ] ≥95 % coverage.
- [ ] `git status` clean on master.

## Coder brief

1. **Invoke `scaffold-library`** with slug `candy-mouse`, namespace `SugarCraft\Mouse\`, role "Self-contained Mark/Scan/Get mouse hit-testing + click dedup".
2. **Read `candy-zone/src/Manager.php`** to understand the current pattern. Mirror its `Mark`/`Scan`/`Get` *semantics* but make `Scanner` standalone — no Manager passed around, the caller owns the Scanner instance.
3. **Sentinel design**: use Unicode Private Use Area codepoints (U+E000..U+F8FF) so they don't collide with ANSI escapes or regular text. Reference bubblezone's approach (zone id encoded inline + position metadata at scan time computed by walking the cell grid).
4. **Use `\SugarCraft\Core\Util\Width::of`** for column accounting during scan (CJK wide chars).
5. **ZoneClickTracker** state machine:
   - Track `lastPressZone` per button.
   - On Press: store (zone, button); emit nothing.
   - On Release: if same zone+button as last Press, emit `ClickResult(zone, button)`; clear state.
   - On Drag: ignore.
   - On Scroll: pass-through (not a click).
6. Run phpunit + check-path-repos.

## Tester brief

- Mark: round-trip — `wrap('a', 'hello')` then scan + get('a') → Zone with content "hello".
- Scan with multiple zones (nested, adjacent, overlapping): correct bounding boxes.
- Hit-test: clicks inside / outside / on edges of zones.
- Wide-char: zone wrapping CJK string — column count matches displayed width, not byte count.
- ZoneClickTracker: Press+Release same zone → Click; Press(A)+Press(A) → no click yet; Press(A)+Release(B) → no click, state cleared; Drag during Press → no spurious clicks.

## Scribe brief

- README: pitch as "no more external Manager wiring". Code example: a button component owns its Scanner; click handling stays local.
- CALIBER_LEARNINGS: "Sentinel codepoints chosen for ANSI safety; do not collapse U+E000+ during normalization."
- MATCHUPS: row referencing bubblezone as upstream (🟢 — port + ZoneClickTracker is a bubblezone-issue-#10 improvement).
- PROJECT_NAMES Candy table.
- Docs/index.html + docs/lib/candy-mouse.html.

## Ship brief

- **PR title**: `candy-mouse: self-contained Mark/Scan/Get + ZoneClickTracker`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-mouse mirrors bubblezone's Mark/Scan/Get pattern but is self-contained — no external Manager wiring needed.
  - ZoneClickTracker dedups Press+Release pairs (addresses bubblezone issue #10).
  - candy-zone is unchanged this PR; sugar-veil/sugar-crumbs/candy-lister migrate to candy-mouse in step-22.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-mouse (≥95% coverage)
  - [x] Wide-char column accounting verified via Width::of integration
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_05.md, docs/repo_map_update.md §327.6, §345.5, §387.4
  ```
- Commit subject: `candy-mouse: self-contained zone tracking + click dedup`.
