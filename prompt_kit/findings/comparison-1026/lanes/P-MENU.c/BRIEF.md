### P-MENU.c — Menu overlay seam: every frame paints, clamps and scans the open menu
- item: P-MENU-REDO (sources: owner pick; latent bugs 1–3 above)
- kind: fix · major · effort M
- approach:
  - **Root causes.** All three are read from source; the lane's regression tests must go red first.
    1. `renderAgentDashboard()` overlays nothing and scans titles only (`src/Tui/Renderer.php:905-927`). Yet F10 (`KeyboardHandler.php:151-153`) and a title click (`App.php:3387-3392`) still open a menu there, so the menu is invisible while it owns the keyboard.
    2. `renderVideoPlayer()` scans row zones (`:1036`) it never paints.
    3. `overlayDropdown()` (`:2145`) splices at `MenuBar::activeMenuColumn()`, which counts unpainted titles (`MenuBar.php:588-605`), and `spliceInto()` (`:2299`) never clamps to `$cols`. The result is an over-wide frame line at 80 cols.
  - **Fix.**
    - One `overlayMenu(App, string $frame, int $cols, int $top): string` splices every `MenuPanel` from `MenuBar::panels()`, clamped to `$cols`.
    - One panel pass in `scanChrome()` reads `MenuBar::panelsMarked()`.
    - All five frame paths call both: `renderView`, `renderAgentDashboard`, `renderMediaForm`, `renderVideoPlayer` and `renderSettingsEditor`.
  - **Behaviour is preserved.**
    - In this step `MenuBar::panels()`/`panelsMarked()` return today's single dropdown as one `MenuPanel`, shifted left to fit. P-MENU.d swaps in the cascade without touching `Tui/Renderer.php` again.
    - `renderDropdown()`/`renderDropdownMarked()` stay as forwarders, since tests read them.
  - `overlayMenu()` runs after P-IMG.b's veil composite, so the menu draws crisply above the popup.
- files.create: none
- files.modify: `candy-code/src/Tui/Renderer.php` (`overlayDropdown()`→`overlayMenu()`, `scanChrome()` panel pass, the tails of `renderView()`, `renderAgentDashboard()`, `renderMediaForm()`, `renderVideoPlayer()` and `renderSettingsEditor()`), `candy-code/src/Tui/Components/MenuBar.php` (new `panels()`, `panelsMarked()`; `renderDropdown*()` forward)
- tests:
  - create `candy-code/tests/Tui/MenuOverlayFrameTest.php`. It is a table over the five frame paths: an open menu is painted AND its row zones land on painted cells. It holds the three regressions:
    - bug 1: the Agents dashboard paints the menu;
    - bug 2: the video player registers no unpainted row zone;
    - bug 3: at 80×24, every menu index reached by F10 then ←/→ gives no line wider than 80.
  - modify `candy-code/tests/Tui/MenuDropdownTest.php` and `candy-code/tests/Tui/MenuBarMouseTest.php` (`testTheAgentDashboardFrameStillRecordsTheMenuTitles` now also records rows).
- docs/guards: none
- serialized: [renderer:Tui/Renderer.php overlayDropdown + scanChrome + the five frame-path tails] [hot:candy-code/src/Tui/Components/MenuBar.php]
- deps: P-MENU.a, P-IMG.b (serial: layout)
- phase: normal
- ruling: none
- risks: P-IMG.b recomposes `renderMediaForm` over a veiled backdrop. Rebase onto it and keep its "menu bar stays clickable" assertion green.

