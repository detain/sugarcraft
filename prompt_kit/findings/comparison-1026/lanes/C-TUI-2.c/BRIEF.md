### C-TUI-2.c — TUI split diffs, highlighted diff bodies, `diffStyle`
- item: C-TUI-2 (sources: GAP-UI-05, GAP-UI-04)
- kind: gap · effort M
- approach:
  - Split mode: `Renderer::renderDiff()` (`candy-code/src/Renderer.php` ~L5388-5600) paints unified rows through `DiffGutter::forDiff()`. Add a split layout from `SideBySideFormat::fromUnified()` when the new `diffStyle` setting (`auto|unified|split`, default `auto`) resolves to split. `auto` means split at ≥ 120 cols.
  - Highlighting: tint tokens inside add/remove/context lines by `RegexHighlighter::languageForPath()` of the file header. The marker column keeps its colour.
  - The PR #1403 width invariant holds: each half is clipped to `(cols − gutter)/2` after tab expansion.
  - The setting is cosmetic, project-settable and Live, read through `UiSettings`.

  What the user sees: wide terminals get two-column diffs, and diff bodies are coloured.
- files.create: none
- files.modify:
  - `candy-code/src/Renderer.php`: `renderDiff()`, `styleDiffLine()`, a new `diffStyle()` reader.
  - `candy-code/src/Tui/DiffGutter.php`: per-side gutters.
  - `candy-code/src/Config/Settings/Definitions/InterfaceSettings.php`: the `diffStyle` definition.
  - `candy-code/src/Config/LayeredSettings.php`: the LAYERED list (~L600-625).
  - `candy-code/src/Config/Settings/UiSettings.php`: `KEYS`.
  - `candy-code/lang/en.php`: `settings.diffStyle.*`.
  - `candy-code/docs/SETTINGS.md`: regenerate.
  - `candy-code/README.md`: the layered roster, regenerated.
- tests:
  - Create `candy-code/tests/Tui/DiffSplitRenderTest.php`: split at width, no row wider than cols, tabs expanded, highlight spans present.
  - Modify `candy-code/tests/Tui/DiffGutterTest.php`.
- docs/guards: `php tools/gen-settings-doc.php --write`; SettingsSchemaDocDriftTest; ReadmeRosterDriftTest; the DocFigureProseDriftTest settings-count prose; `tests/Renderer/PaneWidthInvariantTest.php` must stay green.
- serialized: [renderer:Renderer::renderDiff/styleDiffLine] [hot:candy-code/src/Config/Settings/Definitions/InterfaceSettings.php] [hot:candy-code/src/Config/LayeredSettings.php] [hot:candy-code/src/Config/Settings/UiSettings.php] [lang:settings.diffStyle.] [docs:SETTINGS.md] [docs:README.md]
- deps: C-TUI-2.a, C-TUI-2.b
- phase: normal
- ruling: none
- risks: an over-wide row reintroduces the absolute-cursorTo row collision, so the split width math is the one thing to pin.

