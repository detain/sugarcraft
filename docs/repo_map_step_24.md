# Step 24 — Vim mode consolidation

**Branch:** `ai/vim-mode-shared`
**Depends on:** step-15 (candy-forms migrated), step-16 (sugar-prompt migrated), step-14 (sugar-bits migrated), step-21 (sugar-readline migrated)
**Blocks:** —

## Goal

Consolidate the 4 independent vim-mode implementations (candy-forms TextInput, sugar-prompt, sugar-bits, sugar-readline) behind a single `VimKeyHandler`. Today each lib has its own `vimMode` / `vimNormalMode` flags and keybinding tables — same feature, 4 codepaths.

Reference: `docs/repo_map_update.md` §387.7.

## Files expected to be created

- `candy-forms/src/Vim/VimKeyHandler.php` — handler living in candy-forms (since candy-forms TextInput is the canonical implementation per reconnaissance). Alternative location candy-core: coder writes a recommendation to `docs/repo_map_updates.md` and proceeds with candy-forms unless updates.md says otherwise.
- `candy-forms/src/Vim/VimState.php` — enum (Insert, Normal, Visual, VisualLine).
- `candy-forms/src/Vim/VimAction.php` — enum of mapped actions (CursorLeft, CursorRight, CursorWord, DeleteChar, DeleteLine, YankLine, Paste, etc.).

## Files expected to be modified

- `candy-forms/src/TextInput/TextInput.php` — `vimMode`/`vimNormalMode` flags route through `VimKeyHandler::handle($keyMsg, $vimState)` returning `?VimAction`.
- `sugar-prompt/composer.json` + relevant source files — depend on candy-forms; use `VimKeyHandler`.
- `sugar-bits/composer.json` (already has candy-forms? confirm) + source — same.
- `sugar-readline/composer.json` + source — same.

## Acceptance criteria

- [ ] Single `VimKeyHandler` class handles vim keybindings; 4 libs delegate to it.
- [ ] Each lib's existing vim-mode tests pass with no behaviour change.
- [ ] Per-lib `vimMode` opt-in flag preserved (users opt in per component).
- [ ] Adding a new vim action to `VimAction` enum + handler benefits all 4 libs at once.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **First step**: append to `docs/repo_map_updates.md` your recommendation for where `VimKeyHandler` lives (candy-forms vs candy-core). Default candy-forms (existing canonical impl). Wait for supervisor approval (it'll either accept by silence, or update updates.md with an override before re-spawning you).
2. **Per-lib audit**: read each lib's vim handling code; build a union table of keybindings used. Some libs might have insert-mode-only; some might have visual mode. The handler must support the union; per-lib can opt out of advanced modes by passing a feature-flag.
3. **Implement VimKeyHandler** with the union of keybindings.
4. **Per lib**: replace internal vim branching with `VimKeyHandler::handle()` returning a `VimAction`; consume the VimAction (each lib handles the action because the lib owns its model state).
5. **path-repo closure**: ensure each lib requires the candy-forms (or candy-core) hosting VimKeyHandler.
6. Run phpunit in all 4 libs + dependents.

## Tester brief

- Port each existing vim-mode test (in each lib) to the new handler — they should all still pass.
- Add a cross-lib parity test: same `KeyMsg('w')` in normal mode produces a `CursorWord` action in candy-forms TextInput AND sugar-prompt AND sugar-bits AND sugar-readline.

## Scribe brief

- Each lib's README: note "Vim keybindings via candy-forms VimKeyHandler (shared)".
- CALIBER_LEARNINGS: "Don't add new vim keybindings to per-lib branching — add to VimAction enum + VimKeyHandler so all 4 libs benefit."

## Ship brief

- **PR title**: `vim mode: consolidate behind candy-forms VimKeyHandler`
- **PR body**:
  ```
  ## Summary
  - VimKeyHandler lives in candy-forms (canonical impl); sugar-prompt, sugar-bits, sugar-readline delegate to it.
  - 4 independent vim-mode codepaths collapsed to 1.
  - Per-lib vimMode opt-in flag preserved; consumers control whether to enable vim.
  - VimAction enum is the single extension point — new bindings benefit all 4 libs.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-forms / sugar-prompt / sugar-bits / sugar-readline
  - [x] Cross-lib parity test for shared bindings
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_24.md, docs/repo_map_update.md §387.7
  ```
- Commit subject: `vim mode: consolidate behind candy-forms VimKeyHandler`.
