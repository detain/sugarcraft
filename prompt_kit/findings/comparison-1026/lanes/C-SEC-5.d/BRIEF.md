# C-SEC-5.d — TUI launch trust dialog wiring
- kind: fix · effort M · phase normal
- area: sec
- deps: C-SEC-5.a, C-SEC-5.c
- serialized: [hot:candy-code/src/App/App.php] [hot:candy-code/src/Tui/KeyboardHandler.php] [hot:candy-code/src/Cli/LaunchContext.php] [renderer] (conditional) [docs:PERMISSIONS.md] [lang:tui.trust.]
- ruling: none
- files.create: `candy-code/tests/App/LaunchTrustDialogTest.php`
- files.modify: 
- `candy-code/src/App/App.php` (overlay field, `withTrustDialog`, key arm, grant Cmd, `view` composite)
- `candy-code/src/Tui/KeyboardHandler.php` (modal routing)
- `candy-code/src/Cli/LaunchContext.php` (App build ~:2274)
- `candy-code/docs/PERMISSIONS.md` (launch dialog paragraph)
- `candy-code/lang/en.php` (`tui.trust.granted|failed`)
- `candy-code/src/Tui/Renderer.php` (only if compositing has to live there)
- tests: 
- The new `LaunchTrustDialogTest`:
- an untrusted root with hooks shows the dialog;
- `y` writes `trustedProjectHooks` through a fake `SettingsWriter`;
- a write failure shows the failure notice;
- a trusted root shows nothing;
- `-p` never builds the dialog.
- modify `candy-code/tests/Tui/KeyboardHandlerTest.php` (modal swallows keys).
- docs/guards: `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust prose); `MouseModalGuardTest` if the overlay must refuse clicks underneath.
- risks: `App.php` and `KeyboardHandler.php` are likely P-SESSION-TABS / P-IMAGE-MENU / C-TUI surfaces too. Serialize the lane against them.

## Approach


- At an interactive launch only, never `-p` or serve, `LaunchContext` seeds the App with `TrustDialog::for(Bootstrap::pendingProjectTrust($root))` when the list is non-empty. The App is built at :2274.
- `App` holds the dialog as an overlay field, the way it holds `settingsEditor` (`src/App/App.php:371`). `KeyboardHandler` routes keys to it while it is open.
- Each grant runs as a Cmd through `Bootstrap::grantProjectTrust(target, root, $this->settingsWriter)`, and the result is answered as a notice. This mirrors the existing `App::grantProjectTrust()` (:3838-3874).
- `App::view()` composites the dialog box over the frame that `TuiRenderer::renderView()` returns. If the frame compositor only exists inside `src/Tui/Renderer.php`, the lane also claims [renderer] for that one call.
- The once-per-root "asked" latch reuses the launch-notice dedupe, so a skipped target does not reappear every launch. Keep the latch in process memory, plus a user-tier "skipped" list only if the owner later asks for one.