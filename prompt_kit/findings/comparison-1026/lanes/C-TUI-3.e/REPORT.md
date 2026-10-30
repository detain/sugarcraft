# C-TUI-3.e — One-key verbose toggle (TUI) — LANDED

Branch `cc/C-TUI-3.e`, base master `aa5ddf638`. One commit:
- `abbade61de58b47f127b94b50fc278e973869a21` — `C-TUI-3.e: one-key verbose toggle (Alt+O, session tier)` (cat-file: commit; %ae/%ce both detain@interserver.net; zero Co-Authored trailers; tree clean after).

## What shipped (exactly the design block's files)
- `src/Commands/KeyBindingRegistry.php`: `chat.verbose-toggle` row `Alt+O`, inserted directly after `chat.tool-output` (minimal hunk; C-TOOL-6.a collision law honored — no neighbor reorders).
- `src/Chat/Handlers/HandlesKeys.php`: Alt+O arm after the Alt+A row in `handleKeysMessages` (rune lowered like Alt+M/Alt+A); new `private function toggleVerboseOutput(): array` at trait tail. Writer from `$this->workspace?->service(SettingsWriter::class)` (persistEffort precedent); writes `SettingsTier::Session` `['expandToolOutput' => $on]` (session tier returns before the config door — nothing on disk, mechanically pinned); then `applySettings(['expandToolOutput'], SettingsWriter::SESSION_TARGET)` (Live key, not engine → lands mid-turn at once); generic saved-toast replaced by directional `chat.verbose.on/off` (stale first tick goes inert on the generation check). No writer service → warning toast, never half-applied.
- `lang/en.php`: `keys.chat.verbose-toggle` + `chat.verbose.{failed,off,on,unsavable}` (alphabetical, localized hunks only; en.php is the only locale file in candy-code → no cross-locale duty).
- `README.md`: one Keys-table `Alt+O` row after `Alt+M`.

## Guard rows flipped in-step
- `tests/Commands/KeyBindingDriftTest.php`: new `chat.verbose-toggle` observation (after `chat.agent-cycle`) — drives the label chord twice through a real Chat + workspace writer whose door THROWS, pins `UiSettings::bool` through the real accessor, ambient SessionSettings snapshot/restore in finally (order-independent, leaves process statics clean).
- `tests/Commands/KeyBindingRegistryTest.php`: count asserts 155→156 all/live (dated docblock carry sentence; groups 15, dormant 0 unchanged).
- `tests/Config/Settings/SettingsWriterCensusTest.php`: producer row `'Chat/Handlers/HandlesKeys.php::toggleVerboseOutput->write' => 1` in ksort position + class-docblock "who SAVES" sentence naming the session-tier chord (writeUserConfig door census unaffected — verified green).
- ReadmeRosterDrift Keys-section roster: green without edit (roster 6 pins 4 specific ids, not the whole table); README row added anyway per docs law.

## New test file (durations.tsv row for the welder — I did NOT touch it)
`candy-code/tests/Chat/VerboseToggleKeyTest.php` — 5 tests, 23 assertions. Median runtime **9.86s** (3 runs: 9.644 / 9.862 / 10.046; ~bootstrap-dominated like ApplySettingsTest). Covers: flips + direction toast; door never knocked / config.json never minted; second press restores a SAVED baseline (not the default) with bytes unchanged; mid-turn (`inFlight: true`) lands at once, `pendingSettingsApply() === []`; writer-less Chat reports, no overlay entry.

## Gate results (targeted, plain pipe, cwd=candy-code in worktree)
- `VerboseToggleKeyTest`: 5T/23A OK.
- `SettingsWriterCensusTest|ReadmeRosterDriftTest|KeyBindingRegistryTest`: 48/48, 6379A OK.
- `KeyBindingDriftTest|KeyHelpTest`: 289T — KeyBindingDriftTest fully green incl. new observation; KeyHelpTest 5F **base-proven foreign** (main tree at aa5ddf638 without my edits: identical 5F: /skills hint, footer 156→160, 35→63, ellipsis roster, clip>0 — registry/help drift from other lanes, likely C-TOOL-6.a/neighbors; NOT chased per law).
- Adjacent family `ChatTest|KeyMsg|KeyboardHandler`: 341T/14555A/5F — 5F byte-identical on main-tree base (suggestions/title-model foreign reds). Zero fallout from the Alt+O arm (no existing test presses Alt+O).
- No new warnings anywhere (the 2 PHPUnit deprecations are base-foreign: AccessorDeprecationTest non-public data providers).
- Protocol trio / SETTINGS.md / dist: untouched, no regen duty.

## Seams / risks
- KeyHelpTest's ellipsis-roster pin (#4) expects a specific set of >49-col descriptions; base already red, but whoever fixes those foreign rows must re-measure with my `Alt+O` description (49 cols exactly: 'Toggle verbose tool output for this session only' — currently does NOT trip the clip: it was absent from the actual-vs-expected diff in the worktree run beyond the foreign deltas).
- macOS Option-as-Meta caveat: C-TUI-7.b's notice surface `Bootstrap::MAC_ALT_NOTICE_TEXT` (cli.launch.mac_alt_notice) is already generic Alt-chord wording; no edit needed (design risk line satisfied by the existing notice).
- ESC-prefix terminals delivering Alt+O as ESC+'o': ESC arms cancel-then-own-key, same documented behavior as Alt+M/Alt+A — no extra code, no divergence introduced.
- Weld note: lane adds a test FILE → welder mints the durations row above; suite-figure staleness pair remains the §7 closeout duty.
