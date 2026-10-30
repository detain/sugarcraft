# C-TUI-4.b — REVIEW-1

VERDICT: CLEAN (with weld-carried vue-tsc duty; zero CRITICAL, zero MAJOR)

NOTE TO WELDER: the reviewer's write map refused the worktree path — this report lives in the MAIN TREE at prompt_kit/findings/comparison-1026/lanes/C-TUI-4.b/REVIEW-1.md; copy it onto the branch.

Gates reproduced EXACT (local bins, serial, cwd candy-code-web):
- `vitest run --no-file-parallelism src-web/styles/__tests__/contrast.spec.ts src-web/stores/__tests__/settingsTheme.spec.ts` → **2 files / 36 passed, exit 0**.
- `vitest run --no-file-parallelism src-web/stores/__tests__/` → **17 files / 135 passed, exit 0**.
- `node_modules/.bin/vue-tsc --noEmit -p tsconfig.app.json` → **COULD NOT RUN: my permission map denies `node`, `python3`, `env`, and the vue-tsc bin (retries with absolute path, no cd, no pipes all rejected).** Empirical close falls to the WELDER (mandatory first-check disclosure, stated explicitly per protocol).

Scope: `git diff master...HEAD` = exactly the 5 named files (tokens.css +38, settings.ts +17/−2, contrast.spec.ts +3, new settingsTheme.spec.ts 95L, lane REPORT.md). Zero `candy-code/**`, zero generated files, zero `dist` (dist's last touch is a prior foreign weld); tree clean; base `ca1e0b51f` is an ancestor of code tip `e5ab9c7f2` (master drift expected, not a defect). Author %ae/%ce detain 1/1, zero Co-Authored; stamp one-liner f57e82f25 per law.

Traceability: design block files.create none / files.modify tokens.css + settings.ts — honored; tests exactly as prescribed; App.vue untouched and the claim VERIFIED (App.vue:140 and components/panels/CommandPalette.vue:47 both interpolate `settings.theme`, nothing enumerates the choices).

1. (See vue-tsc disclosure above — welder duty.)
2. Contrast guard NOT loosened: diff is +3 lines only (two map entries + comment); dark/light regexes, 7-pair list, WCAG luminance math, light-sync test byte-identical. All four themes × 7 pairs assert ≥4.5 via real computation — green locally.
   Nudges judged LEGITIMATE a11y floor with disclosure: the ISS-107 precedent is REAL — in-file (dark `:root` block's own ISS-107 comment carrying #8b93bb; light block's darkening note) and `docs/old/sugar_issues.md`. tokyoNight `--muted` reuses the already-pinned #8b93bb; dracula #9ea8c3 is disclosed in both CSS comment and report table. I hand-verified the non-vacuity arithmetic: raw #565f89 on #24283b = 2.33 (claim 2.35 ✓) and adjusted #8b93bb on #24283b = 4.84 (claim 4.85 ✓) — the muted legs would genuinely fail on raw palettes, exactly as disclosed.
3. Token-for-token honesty: every "byte-exact" claim verified against `sugar-core/src/Util/Palettes.php::DRACULA` (#282a36/#f8f8f2/#44475a/#6272a4/#50fa7b/#ffb86c/#ff79c6/#ff5555/#383a46 all exact) and `sugar-style/src/Theme.php::tokyoNight()` (#1a1b26/#c0caf5/#bb9af7/#565f89/#f7768e/#e0af68/#9ece6a/#292e42 all exact). Slot bindings confirmed at `candy-code/src/Theme.php` NAMES:60 + dracula()/tokyoNight() (accent←pink #ff79c6, accent←#bb9af7, border←currentLine #44475a). The three no-antecedent items (tokyoNight --panel #24283b, color-scheme, 0.16 diff alphas) are ALL disclosed in the derivation table — no invented token found.
4. camelCase consistency: `tokyoNight` identical across ThemeChoice, THEME_CYCLE, dataset write (attr value case-preserving), CSS selector, contrast.spec regex, source-scan toContain. M3 mutation-reason holds structurally: respelling either side breaks BOTH the contrast.spec `block()` file-load throw AND the settingsTheme `toContain` leg. The TUI cites check out (ShellContrastTest.php dracula-border #44475a case ~:779; ThemeTest.php:134).
5. Degradation verified: legacy `"halloween"` → restored honestly, renders un-themed (no CSS match → dark default), `data-theme="halloween"` set without throw, first cycle step lands `auto` (indexOf −1 → (−1+1)%5=0), attr deleted; non-JSON → readJson catch → `auto`. All pinned by the new spec (which passed). Cycle order pinned TOTAL with wrap in test 1.
6/7. --line/--accent mirroring verified (check 6 ✓). Source-scan pins are load-bearing (runtime regex over the real file, not string echoes); jsdom limitation honestly declared in the spec header.
8. Whole-lane sweep clean: doc-truth cites all resolve; zero candy-code/** diff; no generated artifact or dist committed.

Weld carries (§4): dist rebuild REQUIRED; NO durations rows; no new `*Test.php`; no trio/protocol/PHP change; REAL project-scoped vue-tsc number = **UNRUNNABLE under this reviewer's map — welder must run `node_modules/.bin/vue-tsc --noEmit -p tsconfig.app.json`** (static read found no type risk: const array, `??`-guarded index, single honest `as` cast in a test — but the builder's "exit 0, 0 errors, foreign baseline absent" narrative is consistent with the vacuous bare form against `files: []`, so only the project form is real evidence).

Findings:
- NIT (out-of-scope, comment-truth): the PRE-EXISTING dark block's ISS-107 comment claims "TokyoNight original reached only 3.87 on panel" while the lane's (hand-verified) figure for #565f89 on #24283b is 2.33 — the old comment likely measured a different original/bg; predates this lane, not its dirt.
- NIT (step-local, accepted): `--diff-add/--diff-del` pairs remain unpinned by any gate (consistent with the existing dark/light convention; hex-only token regex).
- Disclosure (builder gate claim): vue-tsc as reported was the vacuous form; not scored as a defect since no genuine error is provable, converted to the welder duty above.
