# C-TUI-4.b — Web dracula + tokyoNight theme sets — VERDICT: DONE, gates green

Lane branch `cc/C-TUI-4.b` off `ca1e0b51f`. Tip SHA (cat-file'd, lands below): see §Commit.

## Gates (all local bins, serial vitest, cwd candy-code-web)
- `vitest run --no-file-parallelism styles/__tests__/contrast.spec.ts stores/__tests__/settingsTheme.spec.ts` → **36 passed (29 + 7), exit 0**.
- `vitest run --no-file-parallelism src-web/stores/__tests__/` (neighbourhood) → **17 files / 135 tests passed, exit 0**.
- `vue-tsc --noEmit` (local bin) → **exit 0, 0 `error TS` lines** (brief's ~21-error foreign baseline not present in this worktree at ca1e0b51f; mine adds zero).
- No PHP/phpunit run: zero PHP files touched. No new `*Test.php` → **no durations.tsv row needed** (vitest specs never take one). No suite-figure.json change (no PHP tests). No protocol/schema change.

## Mutations proven (cp+md5 restore, never git-checkout)
- M1 dracula `--muted` → raw TUI `#6272a4`: exactly 2 reds (`dracula --muted on --panel`, `--muted on --bg`), 27 passed. Restore md5 `579c35b0…`.
- M2 `THEME_CYCLE` shrunk to 3: exactly 4 reds (all-five-order, tokyoNight-spelling, persistence-to-dracula, auto-wrap), degradation + source-scan legs stay green (correct — CSS unchanged). Restore md5 `687bb5bf…`.
- M3 CSS attr respelled `tokyonight`: contrast.spec file-load throw (block regex) + settingsTheme source-scan leg red — the store↔CSS camelCase `tokyoNight` coupling is pinned on both sides.
- Non-vacuity: raw comment colours measure 2.40 (dracula on panel) / 2.35 (tokyoNight) — the muted legs would fail without the disclosed nudges; the spec would not have caught a copy-paste of the raw palette otherwise… it catches it precisely because the adjusted values are pinned ≥4.5.

## Token → TUI antecedent table (the mandatory derivation)
TUI sources located by grep: `candy-code/src/Theme.php` NAMES list `['dark','light','dracula','tokyoNight','ansi','adaptive']` binds `SugarCraft\Style\Theme::dracula()`/`::tokyoNight()`; Dracula hexes are `SugarCraft\Core\Util\Palettes::DRACULA` (sugar-core/src/Util/Palettes.php:28); Tokyo Night hexes are inline in sugar-style/src/Theme.php:113-129.

| web token | dracula set ← TUI | tokyoNight set ← TUI |
|---|---|---|
| --bg | `Palettes::DRACULA['background']` #282a36, exact | `tokyoNight() background` #1a1b26, exact |
| --panel | `DRACULA['separator']` #383a46, exact literal (Palettes comment "sprinkles-only tint") | **DISCLOSED: #24283b has NO in-repo PHP literal.** It is the existing web `:root` dark block's panel, whose header comment declares "Dark is the default (TokyoNight, as the TUI demos use)". TUI Theme defines no panel slot. Reviewer judges. |
| --line | `DRACULA['currentLine']` #44475a, exact — the sanctioned border slot (Palettes comment "sprinkles 'border'"; pinned TUI-side by candy-code/tests/Tui/ShellContrastTest.php:779 and sugar-style/tests/ThemeTest.php:134) | `tokyoNight() border` #292e42, exact |
| --text | `DRACULA['foreground']` #f8f8f2, exact | `tokyoNight() foreground` #c0caf5, exact |
| --muted | **DISCLOSED DEVIATION:** raw `DRACULA['comment']` #6272a4 reaches only 3.03/2.40 (bg/panel); lightened 40% toward #f8f8f2 → #9ea8c3 (4.75 panel / 6.00 bg). ISS-107 precedent documented in-file. | **DISCLOSED DEVIATION:** raw `tokyoNight() muted` #565f89 reaches 2.35; uses #8b93bb — the same ISS-107-adjusted value the existing dark block carries with its own measured-pair comment (4.85/5.69). |
| --accent | `DRACULA['pink']` #ff79c6, exact — `Theme::dracula()` binds pink→accent (5.97/4.73) | `tokyoNight() accent` #bb9af7, exact (the TUI accent slot — purple; deliberately NOT the dark set's #7aa2f7 which is Tokyo Night's primary) (7.39/6.30) |
| --ok | `DRACULA['green']` #50fa7b, exact (success slot) | `tokyoNight() success` #9ece6a, exact |
| --warn | `DRACULA['orange']` #ffb86c, exact (warning slot; Theme.php:246 notes the TUI itself nudges this one at CONTRAST_MIN 3.0 — the spec's pairs don't gate --warn, mirrored raw) | `tokyoNight() warning` #e0af68, exact |
| --error | `DRACULA['red']` #ff5555, exact | `tokyoNight() error` #f7768e, exact |
| --diff-add | rgb of green #50fa7b / 0.16 (dark-set pattern) | rgb of #9ece6a / 0.16 (identical to existing dark block) |
| --diff-del | rgb of red #ff5555 / 0.16 | rgb of #f7768e / 0.16 (identical to existing dark block) |
| color-scheme | `dark` — no TUI antecedent, platform metadata, matches both sets' darkness | `dark`, same |

Tokens with NO TUI antecedent, flagged per brief: tokyoNight `--panel` (#24283b, web-ramp inheritance), both `color-scheme` lines, the 0.16 alpha on diff pairs (existing dark-set convention, not a palette value). Everything else is a byte-exact palette hex.

## Implementation
- `styles/tokens.css`: two new `:root[data-theme='…']` blocks placed AFTER the light media/explicit blocks so they outrank an OS-light preference at the tied (0,2,0) specificity — the same cascade lever `[data-theme='light']` already uses (comment in-file).
- `stores/settings.ts`: `ThemeChoice` widened (camelCase `tokyoNight` — the exact spelling `candy-code/src/Theme.php` NAMES uses); new module-const `THEME_CYCLE`; `cycleTheme()` walks it, junk restored value (`indexOf` −1) → `auto` on first step — same "first step back to the default" contract as `cycleDiffStyle`; `applyTheme`/persistence untouched (`delete` on auto).
- `styles/__tests__/contrast.spec.ts`: two entries added to the `themes` map only; the dark/light block regexes, the pairs list, the luminance math and the light-sync test are byte-identical (they still pass under M1/M2/M3 with their own legs green).
- `stores/__tests__/settingsTheme.spec.ts` (NEW, 7 tests): five-step cycle order + wrap; `tokyoNight` spelling reaching `dataset.theme`; localStorage persistence under `candy-code-web:theme`; auto clears the attribute; store↔tokens.css source-scan contract (jsdom cannot apply external CSS — pinned as source scan per the batch lesson); legacy `"halloween"` value renders un-themed and unwedges after one cycle; non-JSON store → auto.
- `App.vue`: NOT edited, verified — its button interpolates `settings.theme` directly (line 140), CommandPalette's label likewise; nothing enumerates the choices.

## No collisions
No edits under components/, protocol/, PHP, or generated files. Sibling C-TUI-3.b's files (TranscriptItem.vue/stores/session.ts/SessionView.vue) untouched; my settings.ts/tokens.css edits are exactly the two hot files this step serialized on.

## Weld carries
- **dist rebuild required** (weld duty; dist never staged here).
- No `suite-figure.json` change, no `parallel-tests-durations.tsv` row (vitest specs don't take one), no new `*Test.php`, no protocol/PHP/schema change.

## Commit
One commit `C-TUI-4.b: Web dracula + tokyoNight theme sets` on `cc/C-TUI-4.b`: **e5ab9c7f25a713e58c55b64b9f8c826b13065ec2** (cat-file'd); %ae and %ce each grep-detain 1/1, zero Co-Authored-By. (This stamp is the follow-up one-liner.)
