# BRIEF — P-TABS.g

## P-TABS.g — Shell window strip + Sessions pane, attention badges, OSC 2 title (GAP-UI-N6)

- **area:** picks
- **kind:** gap · effort M
- **effort:** null
- **phase:** normal
- **item:** P-SESSION-TABS (sources: GAP-UI-N6, GAP-SES-12)
- **deps:** P-TABS.a, P-TABS.c, P-TABS.f, P-AGENT.a, P-CTX.e (serial: layout)
- **serialized:** [renderer:Tui/Renderer.php renderView+renderPane; Renderer.php renderSessionTabStrip + AgentStrip call] (layout #3 of 3) [lang] (prefix `tui.window.title.`, `keys.sessions.`) [docs:README.md] [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/Pane.php] [hot:candy-code/src/Tui/Components/MenuBar.php] [hot:candy-code/src/Commands/KeyBindingRegistry.php]
- **ruling:** none
- **risks:** the strip costs one row on short terminals. Drop it below 12 rows (the Sessions pane and Ctrl+Tab still work), and pin the cut-off.

## Files

- candy-code/README.md
- candy-code/lang/en.php
- candy-code/src/App/App.php
- candy-code/src/Commands/KeyBindingRegistry.php
- candy-code/src/Renderer.php
- candy-code/src/Tui/Components/MenuBar.php
- candy-code/src/Tui/Components/SessionsPane.php
- candy-code/src/Tui/Pane.php
- candy-code/src/Tui/Renderer.php
- candy-code/tests/App/TabAttentionTest.php
- candy-code/tests/App/WindowTitleTest.php
- candy-code/tests/Commands/KeyBindingDriftTest.php
- candy-code/tests/Tui/PaneTest.php
- candy-code/tests/Tui/WindowStripShellTest.php

## Regions

{
 "candy-code/src/App/App.php": [
  "windowList()"
 ],
 "candy-code/src/Tui/Renderer.php": [
  "renderPane()",
  "renderView()"
 ],
 "candy-code/src/Renderer.php": [
  "itemsFor",
  "renderSessionTabStrip()"
 ],
 "candy-code/src/Tui/Pane.php": [
  "Sessions"
 ],
 "candy-code/src/Tui/Components/MenuBar.php": [
  "PANE_TABS"
 ],
 "candy-code/src/Tui/Components/SessionsPane.php": [
  "render(App)"
 ],
 "candy-code/src/Commands/KeyBindingRegistry.php": [
  "sessions"
 ],
 "candy-code/lang/en.php": [
  "tui.window.title.*",
  "keys.sessions.*"
 ],
 "candy-code/README.md": [
  "×",
  "+"
 ]
}

## Approach


- `App::windowList()` builds the `WindowList` from the deck: tab names, attention and the summary through `SessionTabs::updateTabSummary` (`⠋ step 4 · 2 agents` from `AgentLiveRegistry::forSession`).
- **Horizontal.** `Tui\Renderer::renderView` (:602) adds one strip row under the menu bar (counted in `$chrome`, :625-630). It shows whenever the deck is armed and the Sessions pane is not docked, even with one tab, so `+` is always reachable. Zones go through `scanChrome`. `Renderer::renderSessionTabStrip` (:3370) returns '' for a deck-hosted Chat, so there is no double strip.
- **Vertical.** Add `Pane::Sessions` (dockable, left) via `renderPane()`. It auto-docks once when the second tab opens.
- **Clicks.** `dispatchChromeClick` handles `win:new` (openTab), `win:s:<key>` (switchTab) and `winx:s:<key>` (closeTab, confirm if busy), plus the Sessions side rows.
- **Attention.**
- The OSC 2 title (`Cmd::setWindowTitle`) is `candy-code — 2 waiting · 1 running`, emitted only on change.
- A background tab reaching Waiting or Unseen fires the existing `TerminalNotifier` (`src/Chat.php:2731`), once per transition.
- The in-frame AgentStrip call site switches to `AgentStrip::itemsFor(…, currentSessionId)`.

## Tests


- create `candy-code/tests/Tui/WindowStripShellTest.php` (the strip row is present with one tab; pane rows drop by exactly 1; never over-wide; a `+` click opens a tab via the factory; × on a busy tab needs a confirm);
- create `candy-code/tests/App/TabAttentionTest.php` (Working/Waiting/Unseen/Failed transitions; notify once per transition);
- create `candy-code/tests/App/WindowTitleTest.php` (OSC 2 only on change);
- modify `candy-code/tests/Tui/PaneTest.php` and `candy-code/tests/Commands/KeyBindingDriftTest.php`.

## Docs/guards

null
