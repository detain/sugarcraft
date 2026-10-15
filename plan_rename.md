# plan_rename.md — SugarCraft package rename (candy-* = apps, sugar-* = libs, honey-* retired)

> **Audience:** this plan is written to be executed step-by-step by an AI coding agent (e.g. OpenCode +
> Qwen) with **no prior context**. Every step names exact files, exact commands, and an exact
> "done when" check. Do not improvise outside a step. If a step's "done when" check fails and the
> step's own instructions don't cover the failure, **STOP and report** — do not guess.
>
> **Repo root:** `/home/sites/sugarcraft` (git remote `detain/sugarcraft`, branch `master`).
> **Written:** 2026-10-14, against `master` @ `a3a769fdd`. **Revised 2026-10-15** against `master` @ `40a4919fc` (after the sugar-crush
> 2026-10-14 review waves: the `~/.sugar-crush` home config dir inventory, PolicySnapshot/MCP trust pins, 22 drift tests incl. the hashed prompt
> snapshot, shell completions, CHANGELOG/GLOSSARY/TROUBLESHOOTING docs, the CI audit step + macOS smoke job). If `master` has moved past
> `40a4919fc` when you start, re-run the inventory greps in Phase 8A item 2 and step 0.1 before trusting the counts here.

---

## Progress tracker

Mark each step `✅ <short-sha>` **as soon as it is committed and verified** and commit that change to this file in the same commit
(or the next one). A step without a ✅ is not done.

| Step | Title | Status |
|---|---|---|
| 0.1 | Baseline snapshot | ✅ 39493799f |
| 0.2 | `tools/renames.json` (the authoritative map) | ✅ cf1a635dc |
| 0.3 | `tools/rename-lib.php` (mechanical rename tool) + tests | ✅ 91e1f148f, b71d77525 |
| 0.4 | `tools/check-rename-residue.php` + tests | ✅ 2399ad4cb |
| 0.5 | `LegacyEnv` + `LegacyPaths` helpers in candy-core | ✅ bedb32a0f |
| 1.1 | Merge candy-zone into candy-mouse | ✅ 6eb05734f |
| 1.2 | Fold sugar-prompt into candy-forms | ✅ 682e6c0f7 |
| 1.3 | Split candy-flip → lib (stays candy-flip for now) + new app `candy-gifview` | ✅ 4d6f81799 |
| 1.4 | Normalize bin names of apps that keep their name | ✅ eab5b7ec3 |
| 2.1 | Verify splitsh-lite multi-prefix history continuity (local experiment) | ✅ 592bca963 |
| 2.2 | `sync-sugarcraft.yml` reads `tools/renames.json` (single-prefix split + legacy-ref preservation per 2.1 verdict) | ✅ 0b6d99af2 |
| 2.3 | `gen-docs.php` emits redirect stubs for old slugs | ✅ 6f1ef69a0 (regen 6b3a7c618) |
| 2.4 | `scripts/rename-remote.sh` (GitHub) + `scripts/packagist.sh` (Packagist) helpers | ✅ 022c0651a (review-fixed 2026-10-15: real Packagist API shape) |
| 2.5 | Create the `candy-gifview` GitHub repo + Packagist package | ☐ |
| 3 | Batch A — pilot (3 renames) | ☐ |
| 4 | Batch B — apps (12 renames) | ☐ |
| 5 | Batch C — components (13 renames) | ☐ |
| 6 | Batch D — media / rendering (7 renames) | ☐ |
| 7 | Batch E — foundation (13 renames) | ☐ |
| 8 | Batch F — sugar-crush → candy-code (+ web) | ☐ |
| 9 | Docs, website, naming rulebook, agent configs | ☐ |
| 10 | GitHub + Packagist finalization (archive, abandon, metadata) | ☐ |
| 11.1 | External: webhooks.interserver.net | ☐ |
| 11.2 | External: php-dup-finder | ☐ |
| 11.3 | External: phlix-console-client | ☐ |
| 11.4 | External: provirted (`composer-tui.json` bundle) | ☐ |
| 12 | Final verification + cleanup | ☐ |

Per-lib progress inside Batches 3–8 is tracked in the `status` field of `tools/renames.json`
(`pending` → `renamed` → `remote-done` → `packagist-done`).

---

## Part A — Ground rules (read before every session)

1. **Commits:** author `Joe Huss <detain@interserver.net>`. **No `Co-Authored-By` trailer.** Commit
   **directly to `master`** — no branches, no PRs. **One commit per step** (per lib, inside the batches).
   Never leave several steps' changes uncommitted in one tree at once.
2. **Do not run `caliber`.** If a pre-commit hook auto-stages `CLAUDE.md`, `AGENTS.md`,
   `CALIBER_LEARNINGS.md`, `.claude/`, `.cursor/`, `.agents/` or `.opencode/` changes that YOUR step did
   not make, unstage them (`git restore --staged <path>`) before committing.
3. **`gh` CLI:** always prefix with `unset GITHUB_TOKEN &&` (e.g. `unset GITHUB_TOKEN && gh repo view sugarcraft/candy-core`).
4. **Shell CWD does not persist** between tool calls. Always `cd /home/sites/sugarcraft && …` or use absolute paths.
5. **Run sub-agents ONE AT A TIME.** Never run two writers concurrently (they collide on `docs/MATCHUPS.md`, `README.md`).
6. **Before pushing:** `cd /home/sites/sugarcraft && git pull --rebase origin master` (the VHS workflow pushes
   "vhs: regenerate demo GIFs" commits to master on its own).
7. **Packagist API token:** obtain it at run time with
   `grep -A1 packagist /home/sites/mystage/auth_login_credentials.txt`. Put it ONLY in a shell variable for
   the command that needs it (`PACKAGIST_TOKEN=… scripts/packagist.sh …`). **Never** write it to any file, commit
   message, plan, log or brief, and never echo it.
8. **Fix, don't disable.** Never skip/delete a failing test, never add a test to an allowlist, never
   `markTestSkipped` to get green. Never delete dormant/unwired code — move or consolidate it.
9. **PHPUnit hangs:** `timeout` does not reliably kill PTY/FFI hangs. For any lib whose suite touches PTYs
   (`candy-pty`, `candy-core`, `candy-wish`, `candy-vcr`, `candy-shell`, `sugar-crush` and their renamed
   successors) run with a background watchdog:
   ```sh
   ( sleep 1800; pkill -9 -f "vendor/bin/phpunit" ) & WD=$!; vendor/bin/phpunit; RC=$?; kill $WD 2>/dev/null; exit $RC
   ```
10. **Stale vendor ≠ real failure.** Before trusting a local phpunit failure, run
    `php scripts/refresh-deps.php --status` and, if the lib is not `linked`,
    `php scripts/refresh-deps.php --mode=linked --libs=<lib>[,<lib>…]`.
11. **Never commit** a per-lib `composer.lock`, never commit a `repositories[]` block in a lib's
    `composer.json`. Gate: `php tools/check-path-repos.php --no-lib-path-repos` must exit 0.
12. **Never hand-edit generated files:** `docs/lib/*.html` (regenerate with `php tools/gen-docs.php`),
    `ansi/README.md` (regenerate with `php tools/gen-ansi-readme.php`).

---

## Part B — The final name map (AUTHORITATIVE)

### B.1 Decisions already made by the user

- `candy-*` = apps/CLI tools (things a human runs). `sugar-*` = libraries (things code `require`s).
- `honey-*` is **retired**: `honey-bounce` → `sugar-spring`, `honey-flap` → `candy-flappy`.
- `candy-zone` is **merged into** `candy-mouse` → final package `sugar-mouse` (zones live in `SugarCraft\Mouse\Zones\`).
- `candy-shell` → `candy-scriptui` (not candy-gum).
- `sugar-crush` → `candy-code`.
- Plain descriptive suffixes (no confection-themed suffixes).

### B.2 Corrections made while writing this plan (verified in source)

- **`candy-forms` → `sugar-forms` (namespace stays `SugarCraft\Forms`), and `sugar-prompt` is folded into it.**
  The first proposal had `candy-forms` → `sugar-widgets-core` and `sugar-prompt` → `sugar-forms`. That was wrong:
  `candy-forms` really does hold the huh form classes (`Form`, `Group`, `Field\*`, `Validator\*`, `Theme`,
  `KeyMap`). `sugar-prompt` is 28 PHP files, and 27 of them are `class_alias` shims onto `SugarCraft\Forms\*`.
  The only real code in it is `src/Spinner.php`. So the lib that *is* the forms library gets the forms name,
  and the façade gets folded in.
- `candy-flip` is split: the library part (Decoder, Frame, AnsiArt, ArtLoop, ArtLibrary, Player, Renderer,
  …) becomes **`sugar-animation`**, because `candy-mosaic`, `sugar-reel` and `candy-top` import it. The
  `bin/candy-flip` viewer becomes a new app, **`candy-gifview`**.
- Bins of every app become the app's slug (e.g. `bin/candy-code`), except `candy-mold`/`candy-starter`'s
  `bin/start` (project template convention).

### B.3 Rename table

`kind`: `app` / `lib`. `ns` = the PSR-4 root under `SugarCraft\` (the `src/` mapping). "—" = unchanged.

| Batch | Old dir / package | New dir / package | kind | Old ns | New ns | Old bin → new bin | GitHub action |
|---|---|---|---|---|---|---|---|
| A | honey-flap | **candy-flappy** | app | Flap | Flappy | `bin/honey-flap` → `bin/candy-flappy` | rename |
| A | candy-mines | **candy-minesweeper** | app | Mines | Minesweeper | `bin/candy-mines` → `bin/candy-minesweeper` | rename |
| A | sugar-calendar | **sugar-datepicker** | lib | Calendar | DatePicker | — | rename |
| B | sugar-stash | **candy-git** | app | Stash | Git | `bin/sugar-stash` → `bin/candy-git` | rename |
| B | candy-query | **candy-sql** | app | Query | Sql | `bin/candy-query` → `bin/candy-sql` | rename |
| B | candy-serve | **candy-git-server** | app | Serve | GitServer | `bin/soft-serve` → `bin/candy-git-server` | rename |
| B | candy-shell | **candy-scriptui** | app | Shell | ScriptUi | `bin/candyshell` → `bin/candy-scriptui` | rename |
| B | sugar-glow | **candy-mdview** | app | Glow | MdView | `bin/sugarglow` → `bin/candy-mdview` | rename |
| B | sugar-spark | **candy-ansi-inspect** | app | Spark | AnsiInspect | `bin/sugarspark` → `bin/candy-ansi-inspect` | rename |
| B | sugar-skate | **candy-kv** | app | Skate | Kv | `bin/skate` → `bin/candy-kv` | rename |
| B | sugar-post | **candy-mail** | app | Post | Mail | `bin/pop` → `bin/candy-mail` | rename |
| B | sugar-wishlist | **candy-ssh-launcher** | app | Wishlist | SshLauncher | `bin/wishlist` → `bin/candy-ssh-launcher` | rename |
| B | sugar-tick | **candy-codetime** | app | Tick | CodeTime | `bin/sugar-tick` → `bin/candy-codetime` | rename |
| B | candy-freeze | **candy-codeshot** | app | Freeze | CodeShot | `bin/candyfreeze` → `bin/candy-codeshot` | rename |
| B | candy-mold | **candy-starter** | app | (`App\`) | (`App\`, unchanged) | `bin/start` (unchanged) | rename |
| C | sugar-boxer | **sugar-panels** | lib | Boxer | Panels | — | rename |
| C | sugar-stickers | **sugar-flexbox** | lib | Stickers | Flexbox | — | rename |
| C | sugar-crumbs | **sugar-navstack** | lib | Crumbs | NavStack | — | rename |
| C | sugar-veil | **sugar-overlay** | lib | Veil | Overlay | — | rename |
| C | candy-hermit | **sugar-quickpick** | lib | Hermit | QuickPick | — | rename |
| C | candy-lister | **sugar-treelist** | lib | Lister | TreeList | — | rename |
| C | sugar-dash | **sugar-dashboard** | lib | Dash | Dashboard | — | rename |
| C | sugar-mcp | **sugar-mcp-client** | lib | Mcp | McpClient | — | rename |
| C | sugar-bits | **sugar-widgets** | lib | Bits | Widgets | — | rename |
| C | candy-forms | **sugar-forms** | lib | Forms | — | — | rename |
| C | candy-mouse | **sugar-mouse** | lib | Mouse | — | — | rename |
| C | candy-focus | **sugar-focus** | lib | Focus | — | — | rename |
| C | candy-fuzzy | **sugar-fuzzy** | lib | Fuzzy | — | — | rename |
| D | candy-shine | **sugar-markdown** | lib | Shine | Markdown | — | rename |
| D | candy-mosaic | **sugar-image** | lib | Mosaic | Image | — | rename |
| D | sugar-reel | **sugar-video** | lib | Reel | Video | — | rename |
| D | candy-flip | **sugar-animation** | lib | Flip | Animation | (bin moved out in 1.3) | rename |
| D | candy-kit | **sugar-cli** | lib | Kit | Cli | — | rename |
| D | honey-bounce | **sugar-spring** | lib | Bounce | Spring | — | rename |
| D | candy-palette | **sugar-colorprofile** | lib | Palette | ColorProfile | — | rename |
| E | candy-sprinkles | **sugar-style** | lib | Sprinkles | Style | — | rename |
| E | candy-layout | **sugar-constraints** | lib | Layout | Constraints | — | rename |
| E | candy-buffer | **sugar-cellbuffer** | lib | Buffer | CellBuffer | — | rename |
| E | candy-ansi | **sugar-ansi-parser** | lib | Ansi | AnsiParser | — | rename |
| E | candy-vt | **sugar-vterm** | lib | Vt | Vterm | — | rename |
| E | candy-input | **sugar-input** | lib | Input | — | — | rename |
| E | candy-async | **sugar-async** | lib | Async | — | — | rename |
| E | candy-testing | **sugar-testing** | lib | Testing | — | — | rename |
| E | candy-log | **sugar-log** | lib | Log | — | — | rename |
| E | candy-metrics | **sugar-metrics** | lib | Metrics | — | — | rename |
| E | candy-wish | **sugar-ssh-server** | lib | Wish | SshServer | — | rename |
| E | candy-pty | **sugar-pty** | lib | Pty | — | — | rename |
| E | candy-core | **sugar-core** | lib | Core | — | — | rename |
| F | sugar-crush | **candy-code** | app | Crush | Code | `bin/sugarcrush` → `bin/candy-code` | rename |
| F | sugar-crush-web | **candy-code-web** | lib (asset bundle of an app) | CrushWeb | CodeWeb | — | rename |

**Structural (Phase 1):**

| Old | Becomes | GitHub action |
|---|---|---|
| candy-zone | merged into candy-mouse (→ sugar-mouse), ns `SugarCraft\Zone` → `SugarCraft\Mouse\Zones` | **archive** `sugarcraft/candy-zone` |
| sugar-prompt | folded into candy-forms (→ sugar-forms), ns `SugarCraft\Prompt` → `SugarCraft\Forms` | **archive** `sugarcraft/sugar-prompt` |
| (new) | **candy-gifview** app, ns `SugarCraft\GifView`, `bin/candy-gifview` | **create** `sugarcraft/candy-gifview` |

**Unchanged names (no dir rename; only their references to other libs change):**
`candy-files`, `candy-top`, `candy-vcr`, `candy-tetris` (apps), and `sugar-table`, `sugar-charts`, `sugar-diff`,
`sugar-gallery`, `sugar-toast` (libs). Package count goes from 62 to 61.

### B.4 Derived names (the tool computes these; listed here so you can sanity-check)

For an entry `old → new`:
- **slug**: `old` / `new` (also the composer name suffix `sugarcraft/<slug>` and GitHub repo `sugarcraft/<slug>`).
- **PascalCase brand**: hyphen-split, ucfirst each part, join: `candy-shine` → `CandyShine`, `sugar-markdown` → `SugarMarkdown`,
  `candy-ansi-inspect` → `CandyAnsiInspect`, `sugar-crush-web` → `SugarCrushWeb`.
- **compact**: slug without hyphens: `candyshine`, `sugarcrush`.
- **env prefixes (old)**: `UPPER_SNAKE(old)_` and `COMPACT_UPPER(old)_` → e.g. `CANDY_SHINE_`, `CANDYSHINE_`; `SUGAR_CRUSH_`, `SUGARCRUSH_`.
- **env prefix (new)**: `UPPER_SNAKE(new)_` → `SUGAR_MARKDOWN_`, `CANDY_CODE_`.
- **Lang domain**: if `<new>/src/Lang.php` exists, its `protected const NAMESPACE = '<x>'` becomes `strtolower(new ns)`
  (e.g. `'shine'` → `'markdown'`, `'crush'` → `'code'`). The `lang/*.php` keys are bare (`Lang::t('theme.read_failed')`),
  so only the constant changes.

---

## Part C — Mechanical rewrite rules (what `tools/rename-lib.php` does)

These rules are the spec for step 0.3. They're applied to **every git-tracked text file** except the exclusions in C.3.
Rules apply **in this order**, and for one entry at a time.

### C.1 Content rules

Let `O` = old slug and `N` = new slug. `ONs`/`NNs` = the old/new namespace segment, `OP`/`NP` = PascalCase, and `OC` = compact.
`PROTECT` = the list of **other** old slugs that start with `O-` (today only: for `sugar-crush` → `sugar-crush-web`).

| # | Rule | Regex (PCRE, applied per line) | Replacement |
|---|---|---|---|
| R1 | Namespace (only if `ONs ≠ NNs`) | `SugarCraft(\\+)ONs(?![A-Za-z0-9_])` | `SugarCraft$1NNs` |
| R2 | PascalCase brand | `(?<![A-Za-z0-9_])OP(?![a-z0-9])` | `NP` |
| R3 | Slug (paths, package names, prose, URLs, CI) | `(?<![A-Za-z0-9_])O(?![A-Za-z0-9_])` plus, for each `p` in PROTECT, a lookahead `(?!` + suffix of p after O + `(?![A-Za-z0-9_]))` | `N` |
| R4 | Compact | `(?<![A-Za-z0-9_$>])OC(?![A-Za-z0-9_])` | `N` — **except** in `*.py` files and paths under `python_port/`, where the replacement is `NC` (new compact, e.g. `candycode`) |
| R5 | Env prefixes | for each old prefix `E`: `(?<![A-Za-z0-9_])E(?=[A-Z0-9])` | new prefix |
| R6 | Lang domain | in `<new>/src/Lang.php` only: `NAMESPACE = 'x'` | `NAMESPACE = '<lower(NNs)>'` |
| R7 | Extra literals (per-entry `extra_literals` map in renames.json) | literal string match | mapped value |

**Line skip:** any line containing the marker `@rename-keep` is never rewritten (used for legacy-compat code and
"formerly known as" notes). In Markdown/HTML use `<!-- @rename-keep -->` at the end of the line, in PHP/shell/YAML use a
trailing `// @rename-keep` / `# @rename-keep` comment.

Why R2 allows uppercase continuation: `SugarCrushWeb`, `SugarCrushServer`, `SugarCrushClient` must become
`CandyCodeWeb`, `CandyCodeServer`, `CandyCodeClient`, and `SugarBoxerTest` must become `SugarPanelsTest`.

Why R3 allows a leading `-` or `.`: `render-sugar-dash`, `gifs-sugar-dash-batch-1`, `.sugar-crush/`, and `vhs-candy-vcr-…` must all be rewritten.

### C.2 Path rules

Every tracked path (outside the exclusions) where any path **segment** matches R2, R3 or R4 is renamed with `git mv` to the
rewritten segment. This covers, among others:
`<old>/` itself, `docs/_data/<old>.json`, `docs/_data/<old>.body.html`, `docs/lib/<old>.html`, `media/icons/<old>.png`,
`docs/img/icons/<old>.png`, `findings/<old>.md`, `findings/plan_<old>.md`, `docs/research/libraries/<old>-research.md`,
`docs/repo_map/sugarcraft_<old>.md`, `docs/repo_map/update_sugarcraft_<old>.md`, `docs/admin/<old>.*`, `docs/dev/<old>.*`,
`ansi/<old>/` and every `ansi/<old>/<old>*.ansi` inside it, `sugar-boxer/src/SugarBoxer.php` → `sugar-panels/src/SugarPanels.php`,
`.sugar-crush/` → `.candy-code/`, `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` → `candy-code/docs/protocol/candy-code.v1.schema.json`,
`sugar-crush/python_port/src/sugarcrush/` → `candy-code/python_port/src/candycode/`.

Bins: the entry's `bins` map is applied explicitly (`git mv <new>/bin/<oldbin> <new>/bin/<newbin>`). Short bin names (`pop`, `skate`,
`wishlist`, `soft-serve`, `tetris`) are **not** rewritten by content rules; R3 rewrites `bin/<oldbin>` path strings only when the
`bins` map lists them (as literal `extra_literals` `"bin/pop": "bin/candy-mail"`). Prose like `` `pop --to …` `` is reported by the
residue checker for manual fix.

### C.3 Exclusions (never rewritten, never renamed)

- `plan_rename.md`, `tools/renames.json`, `tools/rename-lib.php`, `tools/check-rename-residue.php`,
  `tools/tests/RenameLibTest.php`, `tools/tests/CheckRenameResidueTest.php`, `tools/tests/fixtures/rename/**`
- `docs/old/**`, `.remember/**`, `.logs/**`, `.codenomad/**`, `.git/**`
- anything under `*/vendor/**`, `vendor/**`, `*/node_modules/**`
- all `composer.lock` and `package-lock.json` files (regenerated instead)
- `candy-code-web/dist/**` / `sugar-crush-web/dist/**` (rebuilt instead, Phase 8)
- binary files: extensions `gif png jpg jpeg ico webp phar ttf otf woff woff2 mp4 zip gz` and any file containing a NUL byte in its first 8 KB
- `*.ansi` **content** (art). Their **paths** are still renamed (C.2).

### C.4 Things the tool must NOT do

- Do not touch the word `SugarCraft` itself, the `SUGARCRAFT_*` env vars, or `sugarcraft/` as the vendor prefix.
- Do not rewrite bare words such as `shine`, `flip`, `crush`, `dash`, `pop`, `skate`, `boxer` — only the patterns above.
- Do not change `composer.json` `version` constraints (`dev-master` stays `dev-master`).

---

## Phase 0 — Tooling and baseline

### Step 0.1 — Baseline snapshot

1. `cd /home/sites/sugarcraft && git status --porcelain` → must be empty. `git pull --ff-only origin master`.
2. `php scripts/refresh-deps.php --status` → record which libs are not `linked`. Then
   `php scripts/refresh-deps.php --mode=linked` (all libs). This takes a while — run it in the background and wait.
3. Record a baseline test figure for every lib. Write the results to `prompt_kit/briefs/rename-baseline.md`
   (a table: lib, tests, assertions, failures, errors, skipped). Command per lib:
   ```sh
   cd /home/sites/sugarcraft/<lib> && vendor/bin/phpunit --colors=never 2>&1 | tail -3
   ```
   For `sugar-crush` use the sharded runner instead (from the repo root):
   `bash scripts/parallel-tests.sh --help` (read its usage first), then run it the way `.github/workflows/ci.yml`
   step "Run tests (sugar-crush — sharded, E671)" does.
   **Pre-existing failures are recorded, not fixed.** Later steps compare against this file.
4. Also run and record exit codes of the tools guards (from `CONTRIBUTING.md`):
   ```sh
   cd /home/sites/sugarcraft
   candy-core/vendor/bin/phpunit --no-configuration tools/tests/
   php tools/check-path-repos.php --no-lib-path-repos
   php tools/check-path-repos.php
   php tools/check-path-repos.php --unused
   php tools/check-child-lifetimes.php
   php tools/check-one-type-per-file.php
   php tools/gen-docs.php --check
   php tools/gen-ansi-readme.php --check
   ```
5. Check GitHub branch protection on the monorepo so later CI job renames don't strand a required check:
   `unset GITHUB_TOKEN && gh api repos/detain/sugarcraft/branches/master/protection 2>&1 | head -40`.
   Record any `required_status_checks.contexts` that contain a lib slug (e.g. `macOS smoke · sugar-crush`) in the baseline file.

**Done when:** `prompt_kit/briefs/rename-baseline.md` exists and is committed. Commit message: `rename: baseline test figures`.

### Step 0.2 — `tools/renames.json`

Create `tools/renames.json`. It's the single source of truth: the tool, the residue checker, the sync workflow and the docs
redirects all read it. **Copy this content exactly** (the `status` field is updated as work proceeds):

```json
{
  "$comment": "Authoritative old->new package map. See plan_rename.md. status: pending|renamed|remote-done|packagist-done. kind: rename|merge|fold|create.",
  "entries": [
    {"batch":"A","kind":"rename","old":"honey-flap","new":"candy-flappy","ns_old":"Flap","ns_new":"Flappy","type":"app","bins":{"bin/honey-flap":"bin/candy-flappy"},"status":"pending"},
    {"batch":"A","kind":"rename","old":"candy-mines","new":"candy-minesweeper","ns_old":"Mines","ns_new":"Minesweeper","type":"app","bins":{"bin/candy-mines":"bin/candy-minesweeper"},"status":"pending"},
    {"batch":"A","kind":"rename","old":"sugar-calendar","new":"sugar-datepicker","ns_old":"Calendar","ns_new":"DatePicker","type":"lib","bins":{},"status":"pending"},

    {"batch":"B","kind":"rename","old":"sugar-stash","new":"candy-git","ns_old":"Stash","ns_new":"Git","type":"app","bins":{"bin/sugar-stash":"bin/candy-git"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"candy-query","new":"candy-sql","ns_old":"Query","ns_new":"Sql","type":"app","bins":{"bin/candy-query":"bin/candy-sql"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"candy-serve","new":"candy-git-server","ns_old":"Serve","ns_new":"GitServer","type":"app","bins":{"bin/soft-serve":"bin/candy-git-server"},"extra_literals":{"bin/soft-serve":"bin/candy-git-server"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"candy-shell","new":"candy-scriptui","ns_old":"Shell","ns_new":"ScriptUi","type":"app","bins":{"bin/candyshell":"bin/candy-scriptui"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-glow","new":"candy-mdview","ns_old":"Glow","ns_new":"MdView","type":"app","bins":{"bin/sugarglow":"bin/candy-mdview"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-spark","new":"candy-ansi-inspect","ns_old":"Spark","ns_new":"AnsiInspect","type":"app","bins":{"bin/sugarspark":"bin/candy-ansi-inspect"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-skate","new":"candy-kv","ns_old":"Skate","ns_new":"Kv","type":"app","bins":{"bin/skate":"bin/candy-kv"},"extra_literals":{"bin/skate":"bin/candy-kv"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-post","new":"candy-mail","ns_old":"Post","ns_new":"Mail","type":"app","bins":{"bin/pop":"bin/candy-mail"},"extra_literals":{"bin/pop":"bin/candy-mail"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-wishlist","new":"candy-ssh-launcher","ns_old":"Wishlist","ns_new":"SshLauncher","type":"app","bins":{"bin/wishlist":"bin/candy-ssh-launcher"},"extra_literals":{"bin/wishlist":"bin/candy-ssh-launcher"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"sugar-tick","new":"candy-codetime","ns_old":"Tick","ns_new":"CodeTime","type":"app","bins":{"bin/sugar-tick":"bin/candy-codetime"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"candy-freeze","new":"candy-codeshot","ns_old":"Freeze","ns_new":"CodeShot","type":"app","bins":{"bin/candyfreeze":"bin/candy-codeshot"},"status":"pending"},
    {"batch":"B","kind":"rename","old":"candy-mold","new":"candy-starter","ns_old":"App","ns_new":"App","type":"app","bins":{},"status":"pending"},

    {"batch":"C","kind":"rename","old":"sugar-boxer","new":"sugar-panels","ns_old":"Boxer","ns_new":"Panels","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-stickers","new":"sugar-flexbox","ns_old":"Stickers","ns_new":"Flexbox","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-crumbs","new":"sugar-navstack","ns_old":"Crumbs","ns_new":"NavStack","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-veil","new":"sugar-overlay","ns_old":"Veil","ns_new":"Overlay","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-hermit","new":"sugar-quickpick","ns_old":"Hermit","ns_new":"QuickPick","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-lister","new":"sugar-treelist","ns_old":"Lister","ns_new":"TreeList","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-dash","new":"sugar-dashboard","ns_old":"Dash","ns_new":"Dashboard","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-mcp","new":"sugar-mcp-client","ns_old":"Mcp","ns_new":"McpClient","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"sugar-bits","new":"sugar-widgets","ns_old":"Bits","ns_new":"Widgets","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-forms","new":"sugar-forms","ns_old":"Forms","ns_new":"Forms","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-mouse","new":"sugar-mouse","ns_old":"Mouse","ns_new":"Mouse","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-focus","new":"sugar-focus","ns_old":"Focus","ns_new":"Focus","type":"lib","bins":{},"status":"pending"},
    {"batch":"C","kind":"rename","old":"candy-fuzzy","new":"sugar-fuzzy","ns_old":"Fuzzy","ns_new":"Fuzzy","type":"lib","bins":{},"status":"pending"},

    {"batch":"D","kind":"rename","old":"candy-shine","new":"sugar-markdown","ns_old":"Shine","ns_new":"Markdown","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"candy-mosaic","new":"sugar-image","ns_old":"Mosaic","ns_new":"Image","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"sugar-reel","new":"sugar-video","ns_old":"Reel","ns_new":"Video","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"candy-flip","new":"sugar-animation","ns_old":"Flip","ns_new":"Animation","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"candy-kit","new":"sugar-cli","ns_old":"Kit","ns_new":"Cli","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"honey-bounce","new":"sugar-spring","ns_old":"Bounce","ns_new":"Spring","type":"lib","bins":{},"status":"pending"},
    {"batch":"D","kind":"rename","old":"candy-palette","new":"sugar-colorprofile","ns_old":"Palette","ns_new":"ColorProfile","type":"lib","bins":{},"status":"pending"},

    {"batch":"E","kind":"rename","old":"candy-sprinkles","new":"sugar-style","ns_old":"Sprinkles","ns_new":"Style","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-layout","new":"sugar-constraints","ns_old":"Layout","ns_new":"Constraints","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-buffer","new":"sugar-cellbuffer","ns_old":"Buffer","ns_new":"CellBuffer","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-ansi","new":"sugar-ansi-parser","ns_old":"Ansi","ns_new":"AnsiParser","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-vt","new":"sugar-vterm","ns_old":"Vt","ns_new":"Vterm","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-input","new":"sugar-input","ns_old":"Input","ns_new":"Input","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-async","new":"sugar-async","ns_old":"Async","ns_new":"Async","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-testing","new":"sugar-testing","ns_old":"Testing","ns_new":"Testing","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-log","new":"sugar-log","ns_old":"Log","ns_new":"Log","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-metrics","new":"sugar-metrics","ns_old":"Metrics","ns_new":"Metrics","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-wish","new":"sugar-ssh-server","ns_old":"Wish","ns_new":"SshServer","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-pty","new":"sugar-pty","ns_old":"Pty","ns_new":"Pty","type":"lib","bins":{},"status":"pending"},
    {"batch":"E","kind":"rename","old":"candy-core","new":"sugar-core","ns_old":"Core","ns_new":"Core","type":"lib","bins":{},"status":"pending"},

    {"batch":"F","kind":"rename","old":"sugar-crush","new":"candy-code","ns_old":"Crush","ns_new":"Code","type":"app","bins":{"bin/sugarcrush":"bin/candy-code"},"protect":["sugar-crush-web"],"compact_ident_new":"candycode","extra_literals":{"sugarcraft-crush":"candy-code"},"status":"pending"},
    {"batch":"F","kind":"rename","old":"sugar-crush-web","new":"candy-code-web","ns_old":"CrushWeb","ns_new":"CodeWeb","type":"lib","bins":{},"status":"pending"},

    {"batch":"1","kind":"merge","old":"candy-zone","new":"candy-mouse","ns_old":"Zone","ns_new":"Mouse\\Zones","type":"lib","bins":{},"github":"archive","status":"pending"},
    {"batch":"1","kind":"fold","old":"sugar-prompt","new":"candy-forms","ns_old":"Prompt","ns_new":"Forms","type":"lib","bins":{},"github":"archive","status":"pending"},
    {"batch":"1","kind":"create","old":null,"new":"candy-gifview","ns_old":null,"ns_new":"GifView","type":"app","bins":{},"github":"create","status":"pending"}
  ]
}
```

Notes:
- `merge`/`fold` entries record where old names went, so the residue checker and the docs redirect stubs (step 2.3) can see them.
  After batch C renames `candy-mouse` → `sugar-mouse` and `candy-forms` → `sugar-forms`, update these two entries' `new` field
  to the final slug in the same commit.
- The `sync-sugarcraft.yml` history logic (step 2.2) follows **chains**. `candy-flip` → `sugar-animation` is one hop. Write the
  lookup generically ("all old names whose chain ends at X"), not as one hop.

Add a PHPUnit test `tools/tests/RenamesJsonTest.php` that asserts: valid JSON; every `rename` entry's `old` dir exists **or**
its `new` dir exists (exactly one of them); no two entries share `new` among `rename` entries; every `new` slug starts with
`candy-` when `type` is `app` and with `sugar-` when `type` is `lib`; `status` ∈ the four values.

**Done when:** `candy-core/vendor/bin/phpunit --no-configuration tools/tests/RenamesJsonTest.php` is green. Commit: `rename: add tools/renames.json`.

### Step 0.3 — `tools/rename-lib.php`

Create a CLI tool that implements Part C for ONE entry.

**Usage:**
```
php tools/rename-lib.php --old=<slug> [--dry-run] [--no-move] [--verbose]
```
- Reads the entry with that `old` from `tools/renames.json` (error and exit 2 if missing).
- `--dry-run`: print the planned path renames and a per-file count of content replacements by rule (R1…R7), and change nothing.
- `--no-move`: content rules only, no `git mv` (used by merge/fold steps where files are moved by hand).

**Algorithm (implement exactly):**
1. **Preflight** (exit 2 with a clear message on any failure):
   - `git status --porcelain` is empty.
   - Unless `--no-move`: `<old>/` exists and `<new>/` does not exist.
   - If `ns_old ≠ ns_new`: no file under any `*/src/`, `*/tests/` or `*/composer.json` (tracked) already contains
     `SugarCraft\<ns_new>\` or `SugarCraft\\<ns_new>\\` **as a top-level namespace** (prose in `docs/` doesn't count).
2. **File list:** `git ls-files -z`, minus the exclusions in C.3.
3. **Path renames** (unless `--no-move`): first `git mv <old> <new>`. Then, for every other tracked path, compute the rewritten path by applying
   R2, R3 and R4 to each segment. If it differs, `mkdir -p` the parent and `git mv`. Apply the `bins` map after the directory move
   (`git mv <new>/bin/<oldbin> <new>/bin/<newbin>`).
   Process longer paths first, so a file is moved before the directory that contains it.
4. **Content rewrite:** for each text file in the (post-move) list, apply R1→R7 line by line, skipping lines that contain `@rename-keep`.
   Write the file only if it changed. Keep the original line endings and trailing newline exactly.
5. **R6:** if `<new>/src/Lang.php` exists, rewrite its `NAMESPACE` constant.
6. **Post-checks** (print results; exit 1 if any fail, but leave the tree as is for inspection):
   - `php -l` on every changed `.php` file and every file under `<new>/bin/`.
   - `composer validate --no-check-publish --no-check-lock <new>/composer.json` (warnings about `dev-master`/`@dev` are expected — only a hard error fails).
   - `php tools/check-one-type-per-file.php`.
7. Print a summary: number of files renamed, number of files changed, replacements per rule.

**Root override:** the tool must accept `SUGARCRAFT_RENAME_ROOT=<dir>` to operate on another git repo (a test fixture, or an external
consumer in Phase 11). `tools/renames.json` is **always** read from the tool's own directory (`__DIR__ . '/renames.json'`), never from the
target root. With `--no-move`, the preflight "`<old>/` exists" check is skipped, and so is the "`<new>/` must not exist" check, so it works on
repos that contain neither dir. Add an optional `--map=<path>` flag that overrides the renames.json path, for tests.

**Tests:** `tools/tests/RenameLibTest.php` with fixtures under `tools/tests/fixtures/rename/`, building a temp git repo per test. Required cases (one test each):
1. R1 rewrites `SugarCraft\Shine\Foo`, `SugarCraft\\Shine\\Foo` (composer JSON form), and `namespace SugarCraft\Shine;` — but **not** `SugarCraft\ShineX`.
2. R3 rewrites `candy-shine`, `sugarcraft/candy-shine`, `docs/lib/candy-shine.html`, `.candy-shine/` and `render-candy-shine` — but not `candy-shiner` or `xcandy-shine`.
3. PROTECT: with entry `sugar-crush`, the text `sugar-crush-web` is unchanged while `sugar-crush/` and `sugar-crush.` change.
4. R2: `SugarCrushWeb` → `CandyCodeWeb` and `SugarBoxerTest` → `SugarPanelsTest`, but `SugarCrushx` is unchanged.
5. R4: `sugarcrush serve` → `candy-code serve`, while `$sugarcrushDir` and `sugarcrush_x` are unchanged, and in `foo.py` `import sugarcrush` → `import candycode`.
6. R5: `SUGARCRUSH_MODEL` and `SUGAR_CRUSH_SHARE_UPLOAD_URL` → `CANDY_CODE_MODEL` and `CANDY_CODE_SHARE_UPLOAD_URL`; `SUGARCRAFT_X` is unchanged.
7. `@rename-keep` lines are untouched.
8. Path rename moves `media/icons/candy-shine.png` and `ansi/candy-shine/candy-shine2-tc-l-1.ansi`, and leaves `.ansi` content unchanged.
9. Excluded files (`plan_rename.md`, `docs/old/x.md`, a `composer.lock`) are untouched.
10. `--dry-run` changes nothing on disk.

**Done when:** `candy-core/vendor/bin/phpunit --no-configuration tools/tests/` is fully green, **and**
`php tools/rename-lib.php --old=honey-flap --dry-run` prints a sane plan (`honey-flap/` → `candy-flappy/`,
`ns Flap→Flappy`, `bin/honey-flap` → `bin/candy-flappy`). Commit: `rename: add tools/rename-lib.php`.

### Step 0.4 — `tools/check-rename-residue.php`

A read-only checker. **Usage:** `php tools/check-rename-residue.php [--old=<slug>] [--all-done]`.

- `--old=X`: check only that entry. `--all-done`: check every entry whose `status` ≠ `pending`.
- For each checked entry, scan the same file set as the tool (C.3 exclusions) for:
  R1 old-namespace, R2 old-PascalCase, R3 old-slug, R4 old-compact, R5 old-env-prefix and R7 old literals,
  plus, for each `bins` key, the bare old bin name in backticks or after `$ ` / `php ` (e.g. `` `pop `` or `$ skate`).
- Lines with `@rename-keep` are ignored.
- Output `file:line: [rule] matched-text`. Exit 1 if any match, 0 otherwise.
- Tests in `tools/tests/CheckRenameResidueTest.php`: a clean fixture exits 0, a fixture with one leftover exits 1 and names the line,
  and a `@rename-keep` line is ignored.

**Done when:** tools tests are green. Commit: `rename: add tools/check-rename-residue.php`.

### Step 0.5 — `LegacyEnv` and `LegacyPaths` helpers (in candy-core, before it's renamed)

Renamed **apps** must keep working for existing users who still set old env vars or have data in old dirs.

1. Create `candy-core/src/Util/LegacyEnv.php` (namespace `SugarCraft\Core\Util`, `final class LegacyEnv`):
   ```php
   /**
    * Imports env vars set under a package's pre-rename prefix into its new prefix,
    * so a user's existing SUGARCRUSH_* exports keep working after the rename.
    * Never overwrites a var the user already set under the new name.
    *
    * @param array<string,string> $prefixMap old prefix => new prefix, e.g. ['SUGARCRUSH_' => 'CANDY_CODE_']
    * @return list<string> the OLD var names that were imported (for a deprecation notice)
    */
   public static function import(array $prefixMap): array
   ```
   Implementation: iterate `getenv()` (no-arg form returns all vars). For every var whose name starts with an old prefix,
   compute the new name. If `getenv($new) === false`, call `putenv("$new=$value")` and set `$_ENV[$new]` and `$_SERVER[$new]`, then
   add the old name to the result. Return the list in sorted order.
2. Create `candy-core/src/Util/LegacyPaths.php` (`final class LegacyPaths`):
   ```php
   /**
    * One-time move of a pre-rename data/config directory to its new location.
    * Returns a human-readable notice when a move happened, null otherwise.
    * Never moves when the new path already exists (the user may have both; we don't merge).
    */
   public static function migrate(string $oldPath, string $newPath): ?string
   ```
   Implementation: if `is_dir($oldPath) && !file_exists($newPath)`, then `@mkdir(dirname($newPath), 0700, true)` and `rename($oldPath, $newPath)`.
   On success return `"moved {$oldPath} -> {$newPath}"`. On failure return `"could not move {$oldPath} -> {$newPath}: <error>"` and leave
   the old dir in place. Otherwise return null.
3. Tests: `candy-core/tests/Util/LegacyEnvTest.php` (import, no-overwrite, two prefixes, `$_ENV`/`$_SERVER` updated, and cleanup via
   `putenv('X')` in `tearDown`) and `candy-core/tests/Util/LegacyPathsTest.php` (move, new-exists no-op, old-missing no-op, using a temp dir).
4. Mention both helpers in `candy-core/README.md` under a short "Renamed packages" section.

**How apps use them (done later, per app, in Procedure R step R7):** at the very top of the app's `bin/<app>` after the autoloader:
```php
foreach (\SugarCraft\Core\Util\LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_', 'SUGAR_CRUSH_' => 'CANDY_CODE_']) as $old) { // @rename-keep
    fwrite(STDERR, "warning: {$old} is deprecated; use the CANDY_CODE_ prefix\n"); // @rename-keep
}
```
Every line containing an old name **must** carry `@rename-keep`, or the next tool run or the residue checker will flag it.

**Done when:** candy-core suite green (vs baseline). Commit: `candy-core: LegacyEnv + LegacyPaths helpers for package renames`.

---

## Phase 1 — Structural changes under the OLD names

Do these before any renames, so the batches are purely mechanical.

### Step 1.1 — Merge candy-zone into candy-mouse

Facts: `candy-zone/src` = `ClickCounter, DragTracker, Manager, Msg/{DoubleClickMsg,TripleClickMsg,ZoneDragEndMsg,ZoneDragMoveMsg,
ZoneDragStartMsg,ZoneEnterMsg,ZoneExitMsg}, MsgZoneInBounds, ZoneHoverTracker, Zone, Zones`. `candy-mouse/src` already has its own
`Zone.php`, so zone classes go to the sub-namespace `SugarCraft\Mouse\Zones\`. Consumers of `SugarCraft\Zone`: `sugar-bits` (13 refs),
`sugar-gallery` (3), docs, prompt_kit, and externally phlix-console-client.

1. `git mv candy-zone/src/* candy-mouse/src/Zones/` (create the dir first; `Msg/` moves along to `candy-mouse/src/Zones/Msg/`).
2. `git mv candy-zone/tests/*.php candy-mouse/tests/Zones/`. If `candy-zone/tests` has a `bootstrap.php` or fixtures, move them under
   `candy-mouse/tests/Zones/` too, and wire any bootstrap logic into candy-mouse's phpunit bootstrap.
3. In all moved files: `namespace SugarCraft\Zone` → `namespace SugarCraft\Mouse\Zones`, `namespace SugarCraft\Zone\Msg` →
   `namespace SugarCraft\Mouse\Zones\Msg`, `namespace SugarCraft\Zone\Tests` → `namespace SugarCraft\Mouse\Tests\Zones`.
   Fix `use` statements. Inside `SugarCraft\Mouse\Zones`, an unqualified `Zone` now means `Zones\Zone`. Any moved file that meant
   candy-mouse's `Zone` must import it as `use SugarCraft\Mouse\Zone as MouseZone;` (only if the file also declares or uses the local `Zone`).
4. Run the tool in merge mode to fix all remaining references repo-wide:
   `php tools/rename-lib.php --old=candy-zone --no-move` (the entry has kind `merge`, `new=candy-mouse`, `ns_new=Mouse\Zones`).
   This rewrites `SugarCraft\Zone\…` → `SugarCraft\Mouse\Zones\…`, `candy-zone` → `candy-mouse`, `CandyZone` → `CandyMouse`, and
   `CANDY_ZONE_` → `CANDY_MOUSE_`.
5. `candy-mouse/composer.json`: union candy-zone's `require` and `require-dev` into it (skip `sugarcraft/candy-mouse` itself), and add
   `"bubblezone"` to `keywords`. Update `description` to: `Mouse hit-testing (Mark/Scan/Get) plus the bubblezone-style zone manager
   (hover, click/double/triple-click, drag tracking) under SugarCraft\Mouse\Zones.`
6. Every other `composer.json` that required `sugarcraft/candy-zone`: replace it with `sugarcraft/candy-mouse`, or delete the line if
   `candy-mouse` is already required. Check with `grep -rn '"sugarcraft/candy-zone"' --include=composer.json .`.
7. Move `candy-zone/examples/*` → `candy-mouse/examples/zones-<name>.php`, and `candy-zone/.vhs/*.tape` → `candy-mouse/.vhs/zones-<name>.tape`
   (prefix the names to avoid collisions; update the `Output` and `Type "php examples/…"` lines inside each tape). Move the GIFs the same way.
8. README: append candy-zone's README content (minus badges/install) to `candy-mouse/README.md` as a `## Zones (bubblezone)` section.
   Do the same for `CALIBER_LEARNINGS.md` (append a `## From candy-zone` section).
9. Lang: if `candy-zone/lang/` exists, merge its keys into `candy-mouse/lang/*.php` (keys must not collide; prefix with `zones.` if they do,
   and update the `Lang::t()` call sites).
10. Delete the now-empty `candy-zone/` (`git rm -r candy-zone` for leftovers: composer.json, phpunit.xml, README, `.assets/`).
11. Root `composer.json`: remove `sugarcraft/candy-zone` from `require` and from `repositories`. Then
    `cd /home/sites/sugarcraft && composer update 'sugarcraft/*' --no-install` and check that `git diff composer.lock` only touches sugarcraft entries.
12. `.github/workflows/vhs.yml`: remove `candy-zone` from both `all=(…)` lists and the matrix `lib: [...]` list.
    `codecov.yml`: remove the `candy-zone` flag block. `scripts/bootstrap-org-repos.sh`: remove the `[candy-zone]` description line.
13. Docs: `docs/_data/candy-zone.json`, `docs/_data/candy-zone.body.html` — move the useful body content into
    `docs/_data/candy-mouse.body.html` under a "Zones" heading, then `git rm` the zone data files. `git rm docs/lib/candy-zone.html`
    (step 2.3 adds a redirect stub later). `git rm media/icons/candy-zone.png docs/img/icons/candy-zone.png`.
    `docs/MATCHUPS.md`: the `lrstanley/bubblezone` row now points to `candy-mouse`, and the candy-zone row is deleted.
    `docs/index.html`: delete the candy-zone tile and add "zones" to the candy-mouse tile's text. Root `README.md`: same.
    `PROJECT_NAMES.md`: delete the candy-zone row.
    `findings/candy-zone.md` → `git mv` to `findings/candy-mouse-zones.md` (same for `plan_`). `docs/research/libraries/candy-zone-research.md`
    and `docs/repo_map/*candy-zone*` → `git mv` to `*candy-mouse-zones*`.
14. `php tools/gen-docs.php` then `php tools/gen-docs.php --check`.
15. Tests: `php scripts/refresh-deps.php --mode=linked --libs=candy-mouse,sugar-bits,sugar-gallery,candy-mines,sugar-crumbs,sugar-veil,sugar-crush`,
    then run the phpunit suites of `candy-mouse`, `sugar-bits` and `sugar-gallery`, plus every lib that
    `grep -rl "sugarcraft/candy-mouse" */composer.json` lists. All must match the baseline.
16. `php tools/check-rename-residue.php --old=candy-zone` → exit 0 (fix any leftovers, or tag legitimate history with `@rename-keep`).
17. Set the candy-zone entry's `status` to `renamed`.

**Done when:** all of the above are green and the tools guards (step 0.1 #4) still pass. Commit: `candy-mouse: absorb candy-zone under SugarCraft\Mouse\Zones`.
Push (Part A rule 6). The GitHub archive of `sugarcraft/candy-zone` happens in Phase 10.

### Step 1.2 — Fold sugar-prompt into candy-forms

Facts: sugar-prompt has 27 `class_alias` files mapping `SugarCraft\Prompt\X` → `SugarCraft\Forms\X` (same relative name) with **one
exception**: `SugarCraft\Prompt\Fuzzy\FuzzyMatcher` → `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`. The only real class is
`sugar-prompt/src/Spinner.php` (a blocking "run action with spinner" driver mirroring huh's `NewSpinner()`), tested by `tests/SpinnerTest.php`
and `tests/SpinnerCeilingTest.php`. It imports `SugarCraft\Bits\Spinner\Style`, which is itself an alias of `SugarCraft\Forms\Spinner\Style`.
`HasHideFunc.php` and `HasDynamicLabels.php` are shim classes because traits can't be aliased — read them before deleting.

1. Build the exact alias map:
   `grep -rh "class_alias" sugar-prompt/src` → write it into the commit message body as `Prompt\X => Forms\Y` lines.
2. Move the spinner: `git mv sugar-prompt/src/Spinner.php candy-forms/src/ActionSpinner.php`. Rename the class to `ActionSpinner` and the
   namespace to `SugarCraft\Forms`. Replace `use SugarCraft\Bits\Spinner\Style as SpinnerStyle;` with
   `use SugarCraft\Forms\Spinner\Style as SpinnerStyle;` — **candy-forms must not depend on sugar-bits** (that would be a dependency cycle).
   Fix the doc-comment's `{@see BitsSpinner}` reference. Keep the `Mirrors charmbracelet/huh.NewSpinner` citation.
   (The name is `ActionSpinner`, not `Spinner`, because `SugarCraft\Forms\Spinner\` is already a namespace directory.)
3. Move its tests: `git mv sugar-prompt/tests/SpinnerTest.php candy-forms/tests/ActionSpinnerTest.php` (same for `SpinnerCeilingTest` →
   `ActionSpinnerCeilingTest`). Fix namespaces to `SugarCraft\Forms\Tests` and class refs to `ActionSpinner`.
4. `HasHideFunc` / `HasDynamicLabels`: if `candy-forms` already provides the equivalent trait/class (`candy-forms/src/HasHideFunc.php`,
   `HasDynamicLabels.php` exist), consumers switch to those. Don't move these shim classes.
5. Rewrite consumers repo-wide, using `php tools/rename-lib.php --old=sugar-prompt --no-move`. This rewrites
   `SugarCraft\Prompt\` → `SugarCraft\Forms\` and `sugar-prompt` → `candy-forms`. **Then** fix the two exceptions by hand:
   `SugarCraft\Forms\Spinner` (was `Prompt\Spinner`, now wrong) → `SugarCraft\Forms\ActionSpinner`. Find it with
   `grep -rn 'Forms\\Spinner\b[^\\]' --include=*.php .`, and also check `use SugarCraft\Forms\Spinner;` lines.
   `SugarCraft\Forms\Fuzzy\FuzzyMatcher` (was `Prompt\Fuzzy\FuzzyMatcher`) → `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` (add a
   `sugarcraft/candy-fuzzy` require where needed). Known consumers: `candy-core` (2 refs), `candy-forms` (1), `candy-fuzzy` (1), `candy-shell` (1),
   `docs` (20), `findings` (4).
6. Move `sugar-prompt/examples/*.php` → `candy-forms/examples/` (rename on collision with a `prompt-` prefix), and `sugar-prompt/.vhs/*.tape` and
   `*.gif` → `candy-forms/.vhs/` (same collision rule; fix the paths inside the tapes). Merge sugar-prompt's README usage section into
   `candy-forms/README.md` (`## Forms (huh-style)` section) and its `CALIBER_LEARNINGS.md`.
7. Delete `sugar-prompt/`: `AliasResolutionTest.php` tests only the façade being removed (per AGENTS.md "Façade = alias smoke-test only"),
   so it goes with it. `git rm -r sugar-prompt`.
8. Every `composer.json` that requires `sugarcraft/sugar-prompt` → require `sugarcraft/candy-forms` instead (dedupe). Root `composer.json`: remove it from
   `require` and `repositories`, then `composer update 'sugarcraft/*' --no-install`.
9. Same cleanup list as step 1.1 #12–#14 for `sugar-prompt` (vhs.yml, codecov.yml, bootstrap-org-repos.sh, docs `_data`/`lib`/icons,
   MATCHUPS — the `charmbracelet/huh` row now points to candy-forms — index.html tile, README, PROJECT_NAMES, findings/research/repo_map files
   `git mv` to `*candy-forms-huh*`).
10. Tests: `refresh-deps --mode=linked` for candy-forms, candy-core, candy-fuzzy, candy-shell and sugar-bits, then run their suites and
    the suite of every lib listed by `grep -rl '"sugarcraft/candy-forms"' */composer.json`.
11. `php tools/check-rename-residue.php --old=sugar-prompt` → 0. Set the entry's status to `renamed`.

**Done when:** green vs baseline. Commit: `candy-forms: fold sugar-prompt in (ActionSpinner + huh examples); retire sugar-prompt`. Push.

### Step 1.3 — Split candy-flip: library stays, viewer app becomes `candy-gifview`

Facts: `candy-flip/src` = `AnsiArt, AnsiCell, ArtLibrary, ArtLoop, Decoder, Frame, Lang, Player, Renderer, TickMsg`. `candy-top`, `candy-mosaic`
and `sugar-reel` import `Decoder, Frame, AnsiArt, ArtLoop, ArtLibrary`. The app is `candy-flip/bin/candy-flip` + `examples/` + `.vhs/`.

1. Create `candy-gifview/` with the full lib skeleton (copy the shape of `candy-tetris/`): `composer.json`, `phpunit.xml`, `README.md`,
   `CALIBER_LEARNINGS.md`, `src/`, `tests/`, `bin/`, `.vhs/`, `.assets/icon.png` (copy candy-flip's icon), `examples/`.
   - `composer.json`: `"name": "sugarcraft/candy-gifview"`, `"type": "project"`, description `Terminal GIF and .ansi-art viewer (gifterm port) — the
     command-line front end of sugarcraft/candy-flip.`, PSR-4 `SugarCraft\\GifView\\` → `src/`, autoload-dev `SugarCraft\\GifView\\Tests\\` → `tests/`,
     `"bin": ["bin/candy-gifview"]`, and require `php ^8.3`, `sugarcraft/candy-core: dev-master`, `sugarcraft/candy-flip: dev-master` (plus anything else
     `bin/candy-flip` uses directly). Copy the metadata block (keywords incl. `"sugarcraft"`, `"gifterm"`; homepage; author Joe Huss
     role Maintainer; support) from `candy-flip/composer.json`, with URLs pointing at `candy-gifview`. **No `repositories` block.**
2. `git mv candy-flip/bin/candy-flip candy-gifview/bin/candy-gifview`. Fix its autoloader path (it must work both from the monorepo
   `candy-gifview/vendor/autoload.php` and from a global composer install — copy the probing logic exactly as it is today).
   Remove `"bin"` from `candy-flip/composer.json` and change its `"type"` to `"library"`.
3. If the bin contains argument parsing or app logic beyond calling `Player`/`Renderer`, move that logic into
   `candy-gifview/src/Cli.php` (`SugarCraft\GifView\Cli`) so it's testable, and add `candy-gifview/tests/CliTest.php` with at least: `--help`
   exits 0 and prints usage, and a missing file exits non-zero with an error message. Keep `Player`, `Renderer`, etc. in candy-flip (library).
4. Move the **viewer** demos: `candy-flip/examples/*` and `candy-flip/.vhs/*` that demo the CLI → `candy-gifview/`. Demos that show library
   API usage stay in candy-flip. Fix paths inside the tapes.
5. Wire the new lib everywhere a lib must be wired (AGENTS.md "Adding a lib — checklist"): root `composer.json` (`require` +
   `repositories` path entry), `docs/MATCHUPS.md` (gifterm row → candy-gifview app; candy-flip row stays as a library),
   `PROJECT_NAMES.md`, root `README.md`, `docs/index.html` tile (Apps section),
   `docs/_data/candy-gifview.json` + `docs/_data/candy-gifview.body.html` (model them on `docs/_data/candy-tetris.*`), then `php tools/gen-docs.php`,
   `media/icons/candy-gifview.png` + `docs/img/icons/candy-gifview.png` (copies of candy-flip's), `.github/workflows/vhs.yml` (both lists),
   `codecov.yml` (flag block, copy candy-flip's), `scripts/bootstrap-org-repos.sh` DESCRIPTIONS (`[candy-gifview]="Terminal GIF + .ansi-art viewer — port of gifterm."`),
   and `scripts/parallel-tests-durations.tsv` only if it lists non-crush files (it doesn't today — skip).
6. `composer update 'sugarcraft/*' --no-install` at the root. `php tools/check-path-repos.php` and `--no-lib-path-repos` → 0.
7. Tests: `php scripts/refresh-deps.php --mode=linked --libs=candy-flip,candy-gifview,candy-top,candy-mosaic,sugar-reel`, then run those suites.
8. Set the `candy-gifview` entry's status to `renamed` (meaning "exists locally").

**Done when:** green. Commit: `candy-gifview: new app — viewer CLI split out of candy-flip`. **Do not push yet** — step 2.5 creates the
GitHub repo first. Push right after step 2.5 #1.

### Step 1.4 — Normalize bins of apps that keep their names

- `candy-files`: `git mv candy-files/bin/candyfiles candy-files/bin/candy-files`. Update `composer.json` `bin` and every reference
  (`grep -rn 'candyfiles' --exclude-dir=vendor .`). The Lang `NAMESPACE = 'candyfiles'` → `'files'`.
- `candy-tetris`: `git mv candy-tetris/bin/tetris candy-tetris/bin/candy-tetris`. Update `composer.json` and references
  (`grep -rn 'bin/tetris' --exclude-dir=vendor .`, and README usage lines).
- `candy-top`, `candy-vcr`: already `bin/<slug>`, nothing to do.
- Run both suites. Commit: `candy-files, candy-tetris: bin name = package slug`. Push.

---

## Phase 2 — Remote infrastructure (before the first rename is pushed)

### Step 2.1 — Verify splitsh-lite multi-prefix keeps split history continuous (experiment, no commit)

Goal: after `candy-shine/` → `sugar-markdown/`, the split pushed to `sugarcraft/sugar-markdown` (formerly `sugarcraft/candy-shine`) should be a
**fast-forward** of the existing split history, not an unrelated root.

1. Install the same pinned binary as `.github/workflows/sync-sugarcraft.yml` into the scratch dir:
   ```sh
   D=$(mktemp -d) && cd $D && curl -fsSL https://github.com/splitsh/lite/releases/download/v1.0.1/lite_linux_amd64.tar.gz -o s.tgz \
     && tar -xzf s.tgz ./splitsh-lite && echo "ec46c5a409422bf55b26f7de1faab67c2320362934947f984872b3aedc4a9d99  splitsh-lite" | sha256sum -c - && ./splitsh-lite --help
   ```
   Read `--help` and confirm that `--prefix` may be repeated (form `--prefix=from` or `--prefix=from:to`).
2. Make a throwaway clone: `git clone /home/sites/sugarcraft $D/mono && cd $D/mono`.
3. **Before** renaming anything in the clone: `OLD=$($D/splitsh-lite --prefix=honey-flap)` (prints the split SHA of the current history).
4. Now `git mv honey-flap candy-flappy && git -c user.name='Joe Huss' -c user.email=detain@interserver.net commit -qm test`, then
   `NEW=$($D/splitsh-lite --prefix=honey-flap --prefix=candy-flappy)`.
5. Check: `git merge-base --is-ancestor $OLD $NEW && echo CONTINUOUS`. Also compare `$OLD` with the live repo:
   `git ls-remote https://github.com/sugarcraft/honey-flap refs/heads/master` should print the same SHA (the split is deterministic), unless master
   has commits not yet synced. Delete `$D` afterwards.
6. Record the result in `prompt_kit/briefs/rename-splitsh-check.md`:
   - **CONTINUOUS:** step 2.2 uses multi-prefix.
   - **Not continuous:** step 2.2 uses the **fallback**: before the first force-push to a renamed repo, push the old master to a
     `legacy/<old-slug>` branch and tag `<old-slug>-final` in the split repo, so history isn't lost.

**Done when:** the brief file is committed (`rename: splitsh multi-prefix experiment result`).

### Step 2.2 — `sync-sugarcraft.yml` reads `tools/renames.json`

Edit `.github/workflows/sync-sugarcraft.yml`, step "Split + force-push each affected lib":

1. Before the `for lib in "${libs[@]}"` loop, add a helper that prints all historical prefixes of a dir:
   ```bash
   prefixes_for() {  # prints --prefix=… args: every old slug whose rename chain ends at $1, then $1 itself
     php -r '
       $m = json_decode(file_get_contents("tools/renames.json"), true)["entries"];
       $target = $argv[1]; $out = [];
       $changed = true; $set = [$target => true];
       while ($changed) { $changed = false;
         foreach ($m as $e) { if (($e["kind"] ?? "") !== "rename" || $e["old"] === null) continue;
           if (isset($set[$e["new"]]) && !isset($set[$e["old"]])) { $set[$e["old"]] = true; $changed = true; } } }
       foreach (array_keys($set) as $p) { $out[] = "--prefix=" . $p; }
       echo implode(" ", array_reverse($out));
     ' "$1"
   }
   ```
2. Replace `sha=$(/tmp/splitsh-lite --prefix="$lib")` with
   `# shellcheck disable=SC2046` and `sha=$(/tmp/splitsh-lite $(prefixes_for "$lib"))`. (Use the multi-prefix form only if step 2.1 said
   CONTINUOUS. Otherwise keep the single prefix and add the legacy-branch push described in 2.1 #5, guarded by "the remote master is not an
   ancestor of `$sha`".)
3. Keep `git push --force`. With continuous history it's a fast-forward anyway.
4. Fix the stale comment above the splitsh install step: it claims splitsh-lite gives SHAs identical to `git subtree split`, which is false.
   Say instead that splitsh-lite v1.0.1 output is deterministic for a given prefix set, and that the prefix set includes pre-rename names
   from `tools/renames.json`.
5. Add `tools/renames.json` to the force-all trigger list in `scripts/affected-libs.php` (the array that contains
   `'.github/workflows/sync-sugarcraft.yml'` around line 69), so editing the map re-syncs everything.

**Done when:** `php -l` passes on the snippet extracted to a temp file, `bash -n` passes on the extracted run block, and
`php scripts/affected-libs.php --all | head -c 300` still works. Commit: `sync: split renamed libs with their pre-rename prefixes`. Push.

### Step 2.3 — Redirect stubs for old doc URLs

`https://sugarcraft.github.io/lib/<old>.html` is linked from READMEs on Packagist, old split repos and search engines. Keep those links alive.

1. In `tools/gen-docs.php`, after the per-lib pages are generated, read `tools/renames.json`. For every entry with `status` ≠ `pending` and a
   non-null `old`, compute the **final** slug by following the chain (same logic as step 2.2). For merge/fold entries, use `new` and also follow its chain.
   Write `docs/lib/<old>.html`:
   ```html
   <!doctype html><html lang="en"><head><meta charset="utf-8">
   <title>Moved: {OLD} is now {NEW}</title>
   <link rel="canonical" href="https://sugarcraft.github.io/lib/{NEW}.html">
   <meta name="robots" content="noindex">
   <meta http-equiv="refresh" content="0; url={NEW}.html">
   </head><body><p><code>sugarcraft/{OLD}</code> was renamed to <a href="{NEW}.html"><code>sugarcraft/{NEW}</code></a>.</p></body></html>
   ```
   Never write a stub over a slug that has a real `docs/_data/<slug>.json`.
2. `--check` mode must also verify the stubs (missing or different → drift).
3. Add cases to `tools/tests/GenDocsTest.php` (the test already supports `SUGARCRAFT_GEN_DOCS_ROOT` fixture trees): a renamed entry produces a
   stub, a chained rename points to the final slug, and a pending entry produces no stub.
4. `docs/sitemap.xml` must **not** list stub pages. (It's hand-maintained, and the rename tool rewrites its slugs to the new names, which is correct.)

**Done when:** tools tests green and `php tools/gen-docs.php --check` → 0 (stubs for candy-zone and sugar-prompt exist now, pointing at
candy-mouse and candy-forms). Commit: `gen-docs: redirect stubs for renamed packages`. Push.

### Step 2.4 — Remote helper scripts

1. `scripts/rename-remote.sh <old> <new>` (bash, `set -euo pipefail`, `unset GITHUB_TOKEN` at the top):
   ```bash
   gh repo view "sugarcraft/$old" --json name >/dev/null            # must exist
   if gh repo view "sugarcraft/$new" --json name >/dev/null 2>&1; then echo "sugarcraft/$new already exists" >&2; exit 2; fi
   gh repo rename "$new" -R "sugarcraft/$old" --yes
   desc=$(bash scripts/bootstrap-org-repos.sh --print-description "$new")   # see #3
   gh repo edit "sugarcraft/$new" --description "$desc" --homepage "https://sugarcraft.github.io/lib/$new.html"
   gh repo view "sugarcraft/$new" --json name,url,description
   ```
   Also support `--archive <slug> <successor>`. It clones `sugarcraft/<slug>` into a temp dir, replaces `README.md` with
   `# <slug> has moved\n\nThis package is now part of [sugarcraft/<successor>](https://github.com/sugarcraft/<successor>).`, commits as
   Joe Huss, pushes, then runs `gh repo archive "sugarcraft/<slug>" --yes`.
   Topics: `gh repo edit sugarcraft/<new> --add-topic <x>` — topics are set from the lib's composer `keywords` that are valid GitHub topics
   (lowercase, `[a-z0-9-]`, ≤50 chars). Remove any topic that equals the old slug.
 2. `scripts/packagist.sh` (bash). `create` and `update` read `PACKAGIST_TOKEN` and `PACKAGIST_USERNAME` from the environment and exit 2 if
    either is unset; `check` needs no credentials. **Never echo the token** (no `set -x`); everything printed is additionally piped through a
    token-masker.
    Subcommands (corrected 2026-10-15 review round — the original prescription struck through below used a `Bearer` header, a flat
    `{"repository":"<url>"}` body and a JSON `POST /api/create-package` endpoint, none of which Packagist accepts; the corrected shape is
    PROVEN by this repo's own CI, `.github/workflows/sync-sugarcraft.yml:248-251`, which pings `update-package` successfully on every
    split-repo push):
    - `create <slug>` → register via the canonical maintainer form endpoint: `curl -sS -XPOST --max-time 20
      "https://packagist.org/api/packages/_update.json?username=$PACKAGIST_USERNAME&apiToken=$PACKAGIST_TOKEN"
      --data-urlencode "packages[sugarcraft/<slug>]=https://github.com/sugarcraft/<slug>"`. Print the HTTP code and the masked response body.
      (Needs the MAIN token; this is an "unsafe" endpoint.)
      ~~`curl -sS -XPOST -H "Authorization: Bearer detain:$PACKAGIST_TOKEN" -H 'Content-Type: application/json'
      https://packagist.org/api/create-package -d "{\"repository\":\"https://github.com/sugarcraft/<slug>\"}"`~~ (wrong — kept for history)
    - `update <slug>` → `curl -sS -XPOST --max-time 20 -H 'content-type: application/json'
      "https://packagist.org/api/update-package?username=$PACKAGIST_USERNAME&apiToken=$PACKAGIST_TOKEN"
      -d "{\"repository\":{\"url\":\"https://github.com/sugarcraft/<slug>\"}}"` — request assembly byte-matches the CI call.
      ~~same, but `POST https://packagist.org/api/update-package` with the Bearer/flat-body shape above~~ (wrong — kept for history)
   - `check <slug>` → `curl -sS https://repo.packagist.org/p2/sugarcraft/<slug>~dev.json` and print whether a `dev-master` version exists and the
     `source.reference` SHA it points at.
   - Packagist has **no API to abandon a package**. Abandoning is a manual web-UI action — see Phase 10.
3. `scripts/bootstrap-org-repos.sh`: add a `--print-description <slug>` mode that prints the `DESCRIPTIONS[<slug>]` value (no network) and
   exits. As each batch renames libs, the tool rewrites the keys of that map automatically (R3). Also check every description text: rewrite
   descriptions that still describe the old name, and update the stale "Fourteen pre-built TUI components" text for sugar-bits to match its composer description.

**Done when:** `bash -n` passes on both scripts, `bash scripts/bootstrap-org-repos.sh --print-description candy-core` prints a line, and
`bash scripts/packagist.sh check candy-core` shows candy-core's dev-master SHA. Commit: `scripts: rename-remote.sh + packagist.sh helpers`. Push.

### Step 2.5 — Create candy-gifview remotely

1. `unset GITHUB_TOKEN && gh repo create sugarcraft/candy-gifview --public --description "$(bash scripts/bootstrap-org-repos.sh --print-description candy-gifview)" --homepage https://sugarcraft.github.io/lib/candy-gifview.html`
   (or run `scripts/bootstrap-org-repos.sh` if it supports creating a single repo — read it first).
   Then push the step 1.3 commit to master (Part A rule 6) and wait for the "Sync to sugarcraft org" workflow:
   `unset GITHUB_TOKEN && gh run list -R detain/sugarcraft --workflow sync-sugarcraft.yml -L 3`, then
   `gh run watch <id> -R detain/sugarcraft --exit-status`.
2. Set the default branch to master if needed: `scripts/set-org-default-master.sh` (read it first), or
   `gh repo edit sugarcraft/candy-gifview --default-branch master` once `master` exists.
 3. `PACKAGIST_TOKEN=<from Part A rule 7> PACKAGIST_USERNAME=<packagist account, corrected 2026-10-15: required by the script since step 2.4
    review round> scripts/packagist.sh create candy-gifview`, then `scripts/packagist.sh check candy-gifview` (repeat
   until dev-master appears, up to ~10 min).
4. GitHub → Packagist webhook: renamed repos keep theirs, but a **new** repo has none. The sync workflow pings `update-package` on every push,
   so this is covered. Note it in the commit anyway.
5. Set the candy-gifview entry's status to `packagist-done`. Commit: `rename: candy-gifview published`. Push.

---

## Procedure R — rename ONE entry (used by every batch)

Run this once per entry, in table order within the batch. Each entry is one commit, plus one small status-update commit after the remote steps
(or fold the status into the next entry's commit).

**R1 — Preflight.** `cd /home/sites/sugarcraft && git pull --rebase origin master && git status --porcelain` (must be empty).
`php tools/rename-lib.php --old=<old> --dry-run > /tmp/rename-<old>.txt` — **read it**. Sanity check: the directory move, the bins,
the namespace pair, and that counts are non-zero for R3. If R1 shows 0 replacements while `ns_old ≠ ns_new`, STOP (the map is wrong).

**R2 — GitHub rename first** (so the sync push after R6 lands in an existing repo):
`bash scripts/rename-remote.sh <old> <new>`. GitHub keeps a permanent redirect from `sugarcraft/<old>` (web, git clone/fetch/push, and API).
Don't create a new repo named `<old>` later — that would break the redirect.

**R3 — Run the tool.** `php tools/rename-lib.php --old=<old>`. It must exit 0. If `php -l` fails on a file, open it, fix the cause (usually
an R4 compact replacement inside an identifier — rename the identifier sensibly, e.g. `$sugarcrushDir` stays because R4 skips it, but a
hyphenated result in a PHP identifier position is a bug: rewrite it to camelCase), and re-run `php -l`.

**R4 — Manual follow-ups the tool can't do:**
1. **README header and notice:** in `<new>/README.md`, directly under the H1, add
   `> Formerly published as \`sugarcraft/<old>\` (renamed 2026-10). <!-- @rename-keep -->`.
   Under the namespace-rename rows (ns changed), also add `> Namespace changed: \`SugarCraft\\<ns_old>\\\` → \`SugarCraft\\<ns_new>\\\`. <!-- @rename-keep -->`.
2. **composer.json:** check `description` still reads correctly with the new name, and that `keywords` keeps the upstream Go name (e.g.
   `glamour`) and `"sugarcraft"`. Add the new name's descriptive words (e.g. `"markdown"`). `homepage`/`support` URLs were rewritten by R3 — verify them.
3. **Bins (apps):** if the app's bin is documented with a bare short name (`pop`, `skate`, `wishlist`, `soft-serve`), fix the prose. The
   residue checker lists these (`[bin]` rule).
4. **User data paths (apps only):** find paths that contained the old name:
   `grep -rnE "(\.config|\.local/share|\.local/state|\.cache)/[A-Za-z0-9_.-]+|XDG_[A-Z_]+" <new>/src <new>/bin`.
   For each path that **was** derived from the old slug or compact name (the tool has now rewritten it to the new one), add a one-time
   migration at the top of `bin/<newbin>` (after the autoloader) using `LegacyPaths::migrate(<old path>, <new path>)`. Print any
   returned notice to STDERR. Mark every line that contains an old name `// @rename-keep`.
   **Exception:** paths kept for upstream-tool compatibility (sugar-skate's `~/.config/skate`, sugar-wishlist's `~/.config/wishlist.*`)
   did NOT contain the slug, so they weren't rewritten — leave them.
5. **Env (apps only):** if the residue/dry-run showed R5 replacements, add the `LegacyEnv::import([...])` call (step 0.5) at the top of
   `bin/<newbin>` with both old prefixes mapped to the new prefix, plus the deprecation notice. If the app has a docs page listing env vars,
   add a sentence there: "The pre-rename prefixes `<OLD_A>`/`<OLD_B>` are still read, with a deprecation warning." (tag `@rename-keep`).
6. **Lang:** if `<new>/tests` asserts the Lang domain string, update it.
7. **Class names containing the brand** (e.g. `SugarBoxer` → `SugarPanels`): confirm the file was renamed (C.2) and the test class too.
8. **MATCHUPS / index.html section:** if the entry's `type` differs from the section the row/tile sits in (e.g. sugar-stash was listed under
   Libraries but is now an app `candy-git`), move the row in `docs/MATCHUPS.md` (`## Libraries` vs `## Apps`) and the tile in `docs/index.html`
   (Libraries vs Apps section). Then `php tools/gen-docs.php` (it re-patches the library/app counts in index.html).
9. **ansi art:** `ansi/<new>/` was renamed, and `ansi/ansi.json` keys were rewritten. Run `php tools/gen-ansi-readme.php`. The art itself may
   still show the old wordmark — record it in `prompt_kit/briefs/rename-followups.md` (don't redraw art in this plan).
10. **VHS:** tapes inside `<new>/.vhs/` keep working (paths are lib-relative). GIF content may show the old name in a prompt — leave it; the
    `vhs.yml` workflow re-renders on the next change to the lib.

**R5 — Wire-up & regenerate.**
```sh
cd /home/sites/sugarcraft
composer update 'sugarcraft/*' --no-install          # root lock; diff must touch only sugarcraft entries
php tools/gen-docs.php && php tools/gen-docs.php --check
php tools/gen-ansi-readme.php --check
php scripts/refresh-deps.php --mode=linked --libs=$(php scripts/affected-libs.php --all | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo implode(",", $j["all_libs"]);')
```
(The full linked refresh is needed because every lib that required the old package has a dangling `vendor/sugarcraft/<old>` symlink.
To save time, limit `--libs=` to `<new>` plus the libs that list `sugarcraft/<new>` in their composer.json:
`grep -l '"sugarcraft/<new>"' */composer.json | cut -d/ -f1 | paste -sd,`.)
Delete stale per-lib locks first if composer complains about `sugarcraft/<old>`: `rm -f <lib>/composer.lock` (never committed anyway).

**R6 — Verify.**
1. `php tools/check-rename-residue.php --old=<old>` → exit 0. For each hit, either fix it, or (only for true history — changelog lines, ADRs,
   "formerly" notes) append `@rename-keep`.
2. Tests: `<new>` plus every lib that depends on it, directly or transitively. Commit first (R7), then get the list from:
   `php scripts/affected-libs.php --range HEAD~1..HEAD | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo implode(" ", $j["affected"]), "\n";'`
   Run each suite (watchdog per Part A rule 9). For `sugar-crush` (or `candy-code`), use the sharded runner. Compare with the baseline — any
   new failure must be fixed before pushing.
3. Tools guards (step 0.1 #4) all exit 0.
4. `php tools/check-path-repos.php --no-lib-path-repos` → 0.

**R7 — Commit** (before R6 #2 if you need affected-libs; fix-up commits are fine). Set the entry's `status` to `renamed` in `tools/renames.json`.
Message: `<new>: rename from <old>` + body listing ns change, bin change and legacy shims added. Author Joe Huss, no trailer.

**R8 — Push and watch.** `git pull --rebase origin master && git push origin master`. Then:
```sh
unset GITHUB_TOKEN && gh run list -R detain/sugarcraft -L 5
gh run watch <ci-run-id> -R detain/sugarcraft --exit-status
gh run watch <sync-run-id> -R detain/sugarcraft --exit-status
```
- CI: any red job must be investigated. A root `composer.json` change forces the full matrix — this is expected.
- Sync: confirm `✓ synced <new>` in the log: `gh run view <sync-run-id> -R detain/sugarcraft --log | grep -E "synced (<new>|<old>)"`.
- Check continuity: `git ls-remote https://github.com/sugarcraft/<new> refs/heads/master` and confirm that SHA's parent chain contains
  the old master. (Optional: `gh api repos/sugarcraft/<new>/commits?per_page=3`.)
Set `status` to `remote-done`.

**R9 — Packagist.**
First, once per session, run `grep -A1 packagist /home/sites/mystage/auth_login_credentials.txt`, find the API token in that output by eye, and
`export PACKAGIST_TOKEN='<token>'` in the same shell command chain that uses it (shell state doesn't persist between tool calls). Don't paste it
anywhere else.
```sh
export PACKAGIST_TOKEN='<token>' && cd /home/sites/sugarcraft && bash scripts/packagist.sh create <new>
bash scripts/packagist.sh check <new>     # no token needed; repeat until dev-master shows the pushed SHA
```
Then append `<old> → <new>` to `prompt_kit/briefs/rename-packagist-abandon-checklist.md` (the human abandons in the UI — Phase 10). Set `status` to
`packagist-done`. Commit `rename: <new> published` (this can be folded into the next entry's commit).

**Old Packagist package behavior:** `sugarcraft/<old>` stays installable at its last indexed dev-master, so external consumers keep working until
Phase 11. Its auto-update will start failing (the repo now declares a different name) — this is expected, and it stops mattering once it's abandoned.
**Do not delete old packages** (that breaks existing lock files).

---

## Phase 3 — Batch A (pilot): honey-flap, candy-mines, sugar-calendar

Run Procedure R for each of the three. Extra checks for this pilot batch, because it's validating the tooling:
- After the first entry (`honey-flap` → `candy-flappy`), read the full `git show --stat` and spot-check 10 changed files by eye. Confirm the
  multi-prefix sync produced a continuous history (R8). If anything is off, fix the TOOL (with a test) before continuing — don't hand-patch around it.
- honey-flap has `XDG_CONFIG_HOME` usage: inspect `candy-flappy/src` for its config path. If it was `…/honey-flap…`, add the `LegacyPaths` migration (R4.4).
- candy-mines has `CANDY_MINES_STATS` → becomes `CANDY_MINESWEEPER_STATS`. Add the LegacyEnv shim (R4.5).
- After batch A, `honey-*` has one remaining member (`honey-bounce`, batch D).

**Done when:** all three are `packagist-done`, CI and sync are green, and the tracker row is ✅.

## Phase 4 — Batch B (apps)

Order: `sugar-stash`, `candy-query`, `candy-serve`, `candy-shell`, `sugar-glow`, `sugar-spark`, `sugar-skate`, `sugar-post`, `sugar-wishlist`, `sugar-tick`,
`candy-freeze`, `candy-mold`. Per-entry notes:
- **candy-query → candy-sql:** env `CANDY_QUERY_UPSTREAM*` appear only in `docs/old/` (excluded) — fine. Its composer description says "SQLite
  browser", but it also supports MySQL/Postgres (react/mysql + voryx/pgasync). Fix the description to
  `Terminal SQL client for SQLite, MySQL and PostgreSQL — port of jorgerojas26/lazysql on the SugarCraft stack.` and the
  bootstrap description to match.
- **candy-serve → candy-git-server:** PSR-4 also maps `SugarCraft\Serve\SSH\`, `\Git\`, `\LFS\` → R1 rewrites them to `SugarCraft\GitServer\…`.
  Env `CANDY_SERVE_DATA_PATH`, `CANDY_SERVE_INITIAL_ADMIN_KEYS` → `CANDY_GIT_SERVER_*`, plus the LegacyEnv shim in `bin/candy-git-server`.
  The old bin name `soft-serve` appears in docs — fix the prose (keep one "drop-in for charmbracelet/soft-serve" mention).
- **candy-shell → candy-scriptui:** env `CANDYSHELL_*` (≈47 occurrences, e.g. `CANDYSHELL_FOREGROUND`, `CANDYSHELL_PREFIX`) → `CANDY_SCRIPTUI_*`, plus the
  LegacyEnv shim. Its `docs/` page has a large usage section (41 refs) — check that the rewritten examples read well. `SECURITY.md` mentions it — R3 covers it.
- **sugar-glow → candy-mdview / sugar-spark → candy-ansi-inspect:** env `SUGARGLOW_BIN` / `SUGARSPARK_BIN` are test-only. R5 rewrites them to
  `CANDY_MDVIEW_BIN` / `CANDY_ANSI_INSPECT_BIN`. No shim needed (not user-facing) — confirm by reading where they're read.
- **sugar-skate → candy-kv:** data path `~/.config/skate` is upstream-compatible — leave it. The old bin `skate` → `candy-kv`.
- **sugar-post → candy-mail:** bin `pop` → `candy-mail`. Env `SUGARPOST*`/`SUGAR_POST_*` → `CANDY_MAIL_*` + shim (it holds API keys — users WILL have these set).
- **sugar-wishlist → candy-ssh-launcher:** keep the upstream-compatible `~/.config/wishlist.*` paths.
- **sugar-tick → candy-codetime:** data dir `~/.local/share/sugar-tick` → `~/.local/share/candy-codetime` with `LegacyPaths::migrate`. Env `SUGARTICK_DIR` →
  `CANDY_CODETIME_DIR` + shim. The class `SugarTrackIgnore` is not a brand-of-slug — leave it.
- **candy-freeze → candy-codeshot:** keep "port of charmbracelet/freeze" in the description and keywords.
- **candy-mold → candy-starter:** namespace stays `App\` (it's a project template). The README instructions become
  `composer create-project sugarcraft/candy-starter my-app` — verify.

**Done when:** all 12 are `packagist-done`, CI and sync are green.

## Phase 5 — Batch C (components)

Order (leaf-ish first): `sugar-boxer`, `sugar-stickers`, `sugar-crumbs`, `sugar-veil`, `candy-hermit`, `candy-lister`, `sugar-mcp`, `sugar-dash`, `sugar-bits`,
`candy-forms`, `candy-mouse`, `candy-focus`, `candy-fuzzy`. Notes:
- **sugar-boxer → sugar-panels:** the class `SugarBoxer` becomes `SugarPanels` (`src/SugarPanels.php`, test `SugarPanelsTest`).
- **sugar-stickers → sugar-flexbox:** PSR-4 sub-maps `\Flex\` and `\Table\` → R1 handles them.
- **sugar-dash → sugar-dashboard:** big (997 Core refs inside, 153 VHS tapes). `.github/workflows/vhs.yml` has a dedicated `render-sugar-dash` job,
  `gifs-sugar-dash-batch-*` artifacts, a cache key `composer-sugar-dash-…`, and an `if: contains(…, 'sugar-dash')` — R3 rewrites all of them. Check the
  YAML afterwards with `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/vhs.yml'))"`.
  `tools/check-child-lifetimes.php` has a row keyed `sugar-dash/src/Plugin/ExternalModule.php::startProcess` → R3 rewrites it. Run that tool.
- **sugar-mcp → sugar-mcp-client:** sugar-crush has its own `SugarCraft\Crush\MCP\McpClient` class. Different namespace, no clash.
  `tools/check-child-lifetimes.php` row `sugar-mcp/src/StdioMcpServer.php::start` → rewritten.
- **sugar-bits → sugar-widgets:** 22 files are `class_alias` shims (`SugarCraft\Forms\X` → `SugarCraft\Bits\X`); R1 makes them `→ SugarCraft\Widgets\X`. Fine.
  `vhs.yml`'s Windows comment and `scripts/affected-libs.php` `WINDOWS_LIBS` (`'sugar-bits'`) → rewritten.
- **candy-forms → sugar-forms** (ns unchanged): update the fold entry's `new` to `sugar-forms` in renames.json in this commit.
- **candy-mouse → sugar-mouse** (ns unchanged): update the merge entry's `new` to `sugar-mouse`. `CANDY_MOUSE_` env (formerly `CANDY_ZONE_DEBUG`) →
  `SUGAR_MOUSE_` — it's a debug var. Add the LegacyEnv shim? Libraries have no bin, so instead read both in code: where the var is read, use
  `getenv('SUGAR_MOUSE_DEBUG') ?: getenv('CANDY_ZONE_DEBUG') // @rename-keep`.

## Phase 6 — Batch D (media / rendering)

Order: `honey-bounce`, `candy-palette`, `candy-kit`, `candy-flip`, `candy-mosaic`, `sugar-reel`, `candy-shine`. Notes:
- **honey-bounce → sugar-spring:** after this, no `honey-*` dir remains. `grep -rn 'honey-' --exclude-dir=vendor --exclude-dir=node_modules . | grep -v docs/old`
  should only show `@rename-keep` lines and the "Honey" prefix explanation in PROJECT_NAMES (rewritten in Phase 9).
- **candy-flip → sugar-animation:** candy-gifview requires `sugarcraft/candy-flip` → R3 rewrites it to `sugarcraft/sugar-animation`. Re-run candy-gifview's tests.
- **candy-mosaic → sugar-image:** phlix-console-client uses `SugarCraft\Mosaic` (28 refs) — that's Phase 11. sugar-crush uses it 58 times — R1.
- **sugar-reel → sugar-video:** env `SUGAR_REEL_ROWS` / `SUGAR_REEL_COLS` → `SUGAR_VIDEO_*`. Read both in code with a `// @rename-keep` fallback, like candy-mouse.
  `tools/check-child-lifetimes.php` rows for `sugar-reel/src/AudioPlayer.php` and `Decode/FfmpegDecoder.php` → rewritten.
- **candy-shine → sugar-markdown:** `CLAUDE.md`, `AGENTS.md`, `SECURITY.md`, `.claude/`, `.cursor/` mention it → R3.

## Phase 7 — Batch E (foundation)

Order: `candy-log`, `candy-metrics`, `candy-testing`, `candy-async`, `candy-input`, `candy-ansi`, `candy-vt`, `candy-buffer`, `candy-layout`, `candy-sprinkles`,
`candy-wish`, `candy-pty`, `candy-core`. Notes:
- These have huge fan-out (candy-core: 54 dependents). Expect long test runs. **Do candy-core last**, and only after everything else in E is green.
- **candy-pty → sugar-pty:** `.github/workflows/pty-matrix.yml` has `paths: candy-pty/**`, `cd candy-pty`, cache keys → R3. `scripts/affected-libs.php`
  `MACOS_LIBS` comments → R3. `tools/check-child-lifetimes.php` rows `candy-pty/src/Spawn.php::proc`, `candy-pty/src/Posix/PosixProcess.php::spawn` → R3.
  Env `CANDY_PTY_HANG_BUDGET`, `CANDY_PTY_COPY` (test infra) → `SUGAR_PTY_*`. Read both with `@rename-keep` fallbacks if anything outside tests reads them.
  `candy-pty/tests/bootstrap.php` (HangWatchdog) — run the suite with the Part A rule 9 watchdog.
- **candy-core → sugar-core:** ns stays `Core`. `tools/check-child-lifetimes.php` row `candy-core/src/WorkerPool.php::spawnWorker` → R3.
  `ci.yml` comment "candy-core ⇆ candy-pty circular dep" → R3. `CLAUDE.md` "quirk: candy-core → SugarCraft\Core" becomes moot — Phase 9 rewrites that sentence.
  The `LegacyEnv`/`LegacyPaths` helpers move with it automatically (namespace unchanged).
- **candy-sprinkles → sugar-style:** the class `SugarCraft\Sprinkles\Style` becomes `SugarCraft\Style\Style`. Files that import both it and another `Style`
  (e.g. sugar-bits' `Spinner\Style` alias) already alias one of them — `php -l` and tests will tell. AGENTS/CLAUDE "canonical `candy-sprinkles/src/Style.php`" → R3.
- **candy-vt → sugar-vterm:** candy-vcr (116 refs) depends on it heavily. Run candy-vcr's suite, including the golden tapes.
- **candy-testing → sugar-testing:** 23 libs require-dev it. `LoopPin` references in every `tests/bootstrap.php` use `SugarCraft\Testing` (unchanged ns) — fine.

## Phase 8 — Batch F: sugar-crush → candy-code, sugar-crush-web → candy-code-web

The biggest single rename (~1800 `SUGARCRUSH_*` env refs, 98 distinct env vars, the `.sugar-crush/` project dir, user data dirs, a wire protocol
id, a Python port, a Vue bundle, and drift-pinned docs). Do it in **two commits**: 8A (sugar-crush), then 8B (sugar-crush-web).

### 8A — sugar-crush → candy-code

1. Procedure R1–R3 with `--old=sugar-crush`. The entry has `protect: ["sugar-crush-web"]`, `compact_ident_new: candycode`, and
   `extra_literals: {"sugarcraft-crush": "candy-code"}`. Expected effects (verify each in the diff):
   - `sugar-crush/` → `candy-code/`. `bin/sugarcrush` → `bin/candy-code`. ns `SugarCraft\Crush` → `SugarCraft\Code`.
     `SugarCraft\CrushWeb` is untouched (R1 lookahead).
   - Repo-root `.sugar-crush/` (agent presets, `config.json`, `config.dev.json`, `memory/`, `skills/`) → `.candy-code/`. `.gitignore` entries are rewritten.
   - `SUGARCRUSH_*` and `SUGAR_CRUSH_*` → `CANDY_CODE_*` everywhere, including `scripts/parallel-tests.sh` (`SUGARCRUSH_MCP_DISABLE=1`).
   - The project dir literal `'.sugar-crush'` / `'.sugar-crush/…'` → `'.candy-code…'` (ProjectRoot, LayeredSettings, WorktreeConfig, SkillDiscovery,
     ProposedSkills, PermissionGate, NewRulePrompt, ProjectMemoryWriter, ShareCommand, Bubblewrap, WritePathScope, DirectoryBrowser, ProviderFactory).
   - `~/.config/sugarcraft-crush` (Session.php) → `~/.config/candy-code` (R7). `~/.local/share/sugar-crush` (MCP auth store, OAuth registration,
     McpClient) → `~/.local/share/candy-code`.
   - Log `sugarcrush.log` → `candy-code.log`. Protocol schema `docs/protocol/sugarcrush.v1.schema.json` → `docs/protocol/candy-code.v1.schema.json`.
     Subprotocol `sugarcrush.v1` → `candy-code.v1`, and `sugarcrush.auth.<token>` → `candy-code.auth.<token>` (also in `sugar-crush-web/src-web` and
     `e2e`, because R4 runs repo-wide).
   - `python_port/src/sugarcrush/` → `python_port/src/candycode/`, and Python imports `sugarcrush` → `candycode`.
   - Identity strings: `GitRunner::IDENTITY_NAME 'sugar-crush'` → `'candy-code'`, email `checkpoint@candy-code.invalid`. OSC 9 notifier default
     `'sugarcrush'` → `'candy-code'`. MCP `clientInfo` name / LSP client name `'sugar-crush'` → `'candy-code'`. The session provider default
     `'sugarcrush'` → `'candy-code'` (stored per session — old sessions keep `'sugarcrush'`; harmless, it's a label).
   - `.github/workflows/ci.yml`: `matrix.lib == 'sugar-crush'` conditions, `macOS smoke · sugar-crush`, `php sugar-crush/bin/sugarcrush doctor` →
     `php candy-code/bin/candy-code doctor`. `.github/workflows/web.yml` paths `sugar-crush/src/Server/**` etc. → R3. Note: `sugar-crush-web/**`
     paths are untouched until 8B.
   - `tools/check-child-lifetimes.php` `require_once __DIR__ . '/../sugar-crush/tests/Support/…'` and `$entry === 'sugar-crush'` → R3.
   - `scripts/parallel-tests.sh` (`PHPUNIT="php sugar-crush/vendor/bin/phpunit"`, `-c sugar-crush/phpunit.xml`) and
     `scripts/parallel-tests-durations.tsv` (every `sugar-crush/tests/…` path) → R3.
   - `.opencode/`, `.agents/`, `.claude/`, `prompt_kit/`, `crush_*`/`plan_crush_media.md` docs → R3.
   - **User-facing brand copy:** `lang/en.php` strings that say `SugarCrush` (e.g. `tui.mcp.config_changed`, the `/new` confirm copy) → R2 →
     `CandyCode`. The display brand of the app is `CandyCode` everywhere (README H1, TUI copy, docs prose). `candy-code/CHANGELOG.md` is rewritten
     too (see item 7).
   - CI (added 2026-10-14): the `Audit dependencies (sugar-crush — rv-sec-5)` step (`if: matrix.lib == 'sugar-crush'`, `cd sugar-crush`, and the
     `composer config audit.ignore` comment) and the `macos-smoke` job (`name: 'macOS smoke · sugar-crush'`, cache key `vendor-mac-v3-8.3-sugar-crush-…`,
     `find sugar-crush/src`, `php -l sugar-crush/bin/sugarcrush`, `php sugar-crush/bin/sugarcrush doctor|--help`) → R3/R4. Check that the job's
     echo line now reads `php -l clean across candy-code src/ and bin/`.
2. **Where candy-code keeps user state — the full inventory** (verified against `src/` @ `40a4919fc`; re-verify with
   `grep -rnE "'\.sugar-crush|/\.sugar-crush|sugarcraft-crush|\.local/share/sugar-crush" candy-code/src candy-code/bin` **before** step R3, i.e. on
   the old tree as `sugar-crush/…`, and add anything new to this list):
   | Old location | New location | What's in it |
   |---|---|---|
   | `~/.sugar-crush/` (HOME-rooted, **not** XDG — `Cli\Bootstrap::configDirPath()` = `homePath() . '/.sugar-crush'`; also `trustedConfigDirPath()`) | `~/.candy-code/` | `config.json`, `hooks.yaml`, `mcp-trust.json` (McpTrustPins), `policy-snapshot.json` (Config\PolicySnapshot), `session.db` (+ prompt history), `server/` (`server.lock`, discovery record; overridable by `SUGARCRUSH_SERVER_DIR`), `cache/repomap/*.sqlite` (RepoMap\TagCache), `skills/`, `skills-proposed/`, `rules/`, `rulebooks/`, `commands/`, `AGENTS.md`, `agents/`, `teams/`, `media/`, `exports/`, `logs/` |
   | `~/.config/sugarcraft-crush/session.json` (legacy `src/Session.php`) | `~/.config/candy-code/session.json` | old single-session file |
   | `~/.local/share/sugar-crush/` (`MCP\McpAuthStore`, `MCP\OAuthClientRegistration`, `MCP\McpClient`) | `~/.local/share/candy-code/` | MCP OAuth tokens / client registrations |
   | `<project>/.sugar-crush/` (ProjectRoot, LayeredSettings, WorktreeConfig, Skills, Rules, Commands, PermissionGate plans, ProjectMemoryWriter, ShareCommand exports, worktrees) | `<project>/.candy-code/` | per-project settings, `settings.local.json`, skills, rules, commands, memory, plans, worktrees |
3. **Legacy compatibility — env + home-level dirs** (R4.4/R4.5). Put it in **one** new class, `candy-code/src/Cli/LegacyMigration.php`
   (`final class LegacyMigration`, every line holding an old name tagged `// @rename-keep`). Call `LegacyMigration::run(STDERR)` from
   `bin/candy-code` immediately after the autoloader, **before** anything reads env, resolves HOME, or opens `session.db`:
   ```php
   public static function run($stderr): void
   {
       foreach (LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_', 'SUGAR_CRUSH_' => 'CANDY_CODE_']) as $old) { // @rename-keep
           fwrite($stderr, "candy-code: env {$old} is deprecated, use the CANDY_CODE_ prefix\n"); // @rename-keep
       }
       $owned = HomeDirectory::owned();      // same ownership gate Bootstrap::trustedConfigDirPath() uses — never migrate into an untrusted HOME
       if ($owned === null) { return; }
       $home = rtrim($owned, '/');           // (match HomeDirectory's actual API — read src/Support/HomeDirectory.php)
       // A running pre-rename server holds ~/.sugar-crush/server/server.lock (flock LOCK_EX|LOCK_NB, see Server\StateDir).
       if (self::serverRunning($home . '/.sugar-crush/server/server.lock')) {                                   // @rename-keep
           fwrite($stderr, "candy-code: a sugarcrush server is still running; quit it, then relaunch to migrate ~/.sugar-crush\n"); // @rename-keep
           return;
       }
       foreach ([
           [$home . '/.sugar-crush', $home . '/.candy-code'],                                   // @rename-keep
           [$home . '/.config/sugarcraft-crush', $home . '/.config/candy-code'],                // @rename-keep
           [$home . '/.local/share/sugar-crush', $home . '/.local/share/candy-code'],           // @rename-keep
       ] as [$from, $to]) {
           if (($n = LegacyPaths::migrate($from, $to)) !== null) { fwrite($stderr, "candy-code: {$n}\n"); }
       }
   }
   ```
   `serverRunning()` opens the lock file only if it exists, tries `flock(LOCK_EX | LOCK_NB)`, releases it right away, and returns true only when
   the lock is held by someone else. Before writing the paths, read `src/Session.php`, `src/MCP/McpAuthStore.php`,
   `src/MCP/OAuthClientRegistration.php` and `Cli\Bootstrap::homePath()`/`resolvedHomePath()`. If any of them honours `XDG_CONFIG_HOME`/`XDG_DATA_HOME`,
   mirror that exactly (same base dir, old leaf → new leaf).
   **Why moving the whole dir is safe for the security state:** `rename()` keeps the dir's owner and 0700 mode, so `HomeDirectory`/ownership checks
   still pass. `policy-snapshot.json` stores **content fingerprints** (`userConfig`, `userHooks`) plus trusted roots and hook identities — no
   config-dir paths — so a moved config does **not** trigger the "policy changed while no process was watching" notice or the project-hook
   quarantine. `mcp-trust.json` pins hash MCP entries, not the config dir. **Prove it with a test:** `candy-code/tests/Cli/LegacyMigrationTest.php`
   builds a temp HOME with `.sugar-crush/{config.json,hooks.yaml,policy-snapshot.json,mcp-trust.json}` (`// @rename-keep` on those lines), runs
   `LegacyMigration::run()`, then asserts:
   (a) everything now lives under `.candy-code/` with mode 0700;
   (b) `PolicySnapshot::read()` against the new dir returns the old record, and fingerprints of the moved files equal the recorded ones (no quarantine);
   (c) an existing `.candy-code/` blocks the move;
   (d) a held `server/server.lock` blocks the move and prints the notice;
   (e) `SUGARCRUSH_FOO=1` is imported as `CANDY_CODE_FOO`, and a pre-set `CANDY_CODE_FOO` wins.
   Use the same HOME-pinning helpers the existing Cli tests use — don't write a second harness (`DuplicatedTestHelperDriftTest` will catch a copy).
4. **Project-level dir.** In the single resolver for a project's state dir (`src/Support/ProjectRoot.php` ~line 61 / `src/Config/LayeredSettings.php`
   ~line 133 — find where `<root>/.sugar-crush` is computed): if `<root>/.candy-code` doesn't exist and `<root>/.sugar-crush` does (`// @rename-keep`), call
   `LegacyPaths::migrate()`, and emit the notice through the existing runtime-notice mechanism (`src/Diagnostics/RuntimeNoticeSink.php`):
   `moved .sugar-crush -> .candy-code (if this project tracks it in git, commit the move)` `// @rename-keep`. **Trust lists are keyed by project root**
   (`trustedProjectHooks`/`trustedProjectMcp`/`trustedProjectCommands` in the user config) — not by `.sugar-crush` — so a moved project dir stays trusted.
   Confirm it in the test: `candy-code/tests/Support/ProjectDirMigrationTest.php` builds a temp project with `.sugar-crush/settings.json` and
   `.sugar-crush/hooks.yaml`, marks the root trusted, resolves settings, and asserts the files are now read from `.candy-code/` **and** the project
   hooks still load (no PolicySnapshot quarantine, because the hook identities name|event|command are unchanged).
5. **Shell completions.** `src/Cli/Subcommands.php` generates bash/zsh/fish completion scripts. R4 rewrites the command word
   (`complete -F _sugarcrush sugarcrush` → `… candy-code`, `#compdef candy-code`, `complete -c candy-code …`), but **not** the function
   identifier `_sugarcrush` (R4 skips names preceded by `_`). Rename it by hand to `_candy_code` everywhere (bash function, zsh function, and the
   zsh doc hint `"${fpath[1]}/_candy-code"`). Update the completion tests (`grep -rln "_sugarcrush" candy-code/tests`). Add to `docs/TROUBLESHOOTING.md`:
   "After upgrading from sugar-crush, regenerate your shell completion (`candy-code completion <shell>`), because the old script completes the
   `sugarcrush` command." (`<!-- @rename-keep -->`).
6. **Drift and snapshot guards — what is expected to change.** sugar-crush now has 22 doc-drift tests (`find candy-code/tests -name '*Drift*Test.php'`).
   Most re-derive a doc roster or pin prose needles against `src/`/`lang/`, and R2–R5 rewrite both sides the same way, so they stay green. Handle these by name:
   - **`tests/Prompt/PromptSnapshotDriftTest.php`** — WILL go red. `tests/fixtures/prompt-snapshots/system-sections.txt` stores byte length +
     `sha256/16` per system-prompt slot, and the prompt text contains the brand. Run it, copy the "Actual manifest" from the failure message into
     `system-sections.txt` (and `turn-context.txt` if it also failed), and diff old vs new. **Only** the length/hash columns of slots whose text
     contains the brand may change. Its docblock requires the regeneration to be argued in the commit message — say "brand rename sugar-crush → candy-code;
     slots N,M changed only by the name". `tests/fixtures/prompt/golden-system-prompt.txt` is rewritten by R2/R3 — if `BaseSystemPromptTest` still fails,
     diff and fix by hand.
   - **`tests/Config/ReadmeSuiteFigureDriftTest.php`** + `tests/Config/Support/suite-figure.json` (floor today: 22,564 tests / 423,652 assertions /
     2 skipped, measured 2026-10-14): after the full sharded run is green, regenerate with the command in the file's `regenerate` field, and update the
     README headline (`README.md` "**N tests / M assertions …**") from the same run. This step adds tests (LegacyMigrationTest, ProjectDirMigrationTest),
     so the figure goes up.
   - **`tests/Protocol/ProtocolSchemaDriftTest.php`** — the schema path moves and `tests/fixtures/protocol/server.hello.json` carries the subprotocol/brand.
     Both are rewritten. Green expected.
   - **`tests/Config/DocFigureProseDriftTest.php`** (~6,000 lines of prose needles, incl. the MCP brand needle for `tui.mcp.config_changed`) — needles
     written as literals are rewritten along with the docs. Needles **built by concatenation or regex** (e.g. `'Sugar' . 'Crush'`, `/sugar-?crush/i`) are
     not. For each red, fix the needle to the new name. Never delete a needle.
   - **`tests/Config/EnvRosterDriftTest.php`** — `LegacyEnv::import()` reads `getenv()` with no argument, which the scanner may flag as a
     "whole-superglobal read". If it goes red, document the legacy prefixes in `candy-code/docs/ENVIRONMENT.md` the way the failure message demands
     — **not** an exemption. Its docblock says ENVIRONMENT.md opens with "EVERY ENVIRONMENT VARIABLE SUGARCRUSH …" → now CANDY_CODE; verify the sentence still reads right.
   - **`tests/Support/DuplicatedTestHelperDriftTest.php`** — catches copied test harnesses. Reuse helpers in the new tests (items 3–4).
   - Everything else (`ReadmeRosterDriftTest`, `TrustKeyDocumentationDriftTest`, `ConfigWriteProducerDocumentationDriftTest`, `KeyBindingDriftTest`,
     `CommandDocsDriftTest`, `HookEnvironmentDocumentationDriftTest`, `ServerProtocolDocumentationDriftTest`, `McpStartupOrderingDocDriftTest`,
     `MemoryDocumentationDriftTest`, `GlobFigureDriftTest`, …): expected green. If one goes red, read its failure message — it names the doc line
     and the source of truth.
   - New test files from items 3–5 go into `scripts/parallel-tests-durations.tsv` (path, duration estimate e.g. `1.000`, test count), or they run in zero shards.
7. **Docs the review wave added** (2026-10-14: `docs/README.md` index, `docs/GLOSSARY.md`, `docs/TROUBLESHOOTING.md`, the rewritten newcomer `README.md`, and
   `CHANGELOG.md` with a "Fixed (2026-10-14 review wave)" section): rewritten by R3. Then, by hand:
   - `CHANGELOG.md`: add a new top section `## Renamed: sugar-crush → candy-code (2026-10)` (`<!-- @rename-keep -->`) listing the bin, package,
     namespace, env prefix, `~/.sugar-crush` → `~/.candy-code` and project-dir migrations, the server-must-be-stopped rule, completions to
     regenerate, and protocol `candy-code.v1`. Older entries stay rewritten (consistent names). Don't hand-restore old names there.
   - `docs/GLOSSARY.md`: add an entry "CandyCode — this app; formerly SugarCrush / sugar-crush." (`<!-- @rename-keep -->`).
   - `docs/TROUBLESHOOTING.md`: a "Upgrading from sugar-crush" section — the migration notices, what to do if `~/.candy-code` already existed
     (no merge — move files by hand), the running-server case, completions.
   - `docs/ENVIRONMENT.md`: "Pre-rename `SUGARCRUSH_*` / `SUGAR_CRUSH_*` variables are still read, with a deprecation warning." (`<!-- @rename-keep -->`).
8. **Untracked local dirs** (not moved by `git mv`): `mv .sugar-crush-build .candy-code-build` if present. Check for any other untracked
   `sugar-crush*` dirs: `git status --ignored --porcelain | grep -i crush`. The developer's own `~/.sugar-crush`, `~/.config/sugarcraft-crush` and
   `~/.local/share/sugar-crush` migrate on the first run of the new bin. **Quit every running `sugarcrush` TUI/server on this machine first**
   (`pgrep -af 'bin/sugarcrush'`).
9. Procedure R5–R9 as usual. The CI `macOS smoke` job name changes. If step 0.1 #5 found it in required status checks, update branch protection:
   `unset GITHUB_TOKEN && gh api -X PATCH repos/detain/sugarcraft/branches/master/protection/required_status_checks -f …` (or tell the human).

### 8B — sugar-crush-web → candy-code-web

1. Procedure R1–R3 with `--old=sugar-crush-web` (ns `CrushWeb` → `CodeWeb`, `SugarCrushWeb` was already turned into `CandyCodeWeb` by 8A's R2 — fine).
2. `candy-code-web/package.json` `"name"` is now `candy-code-web`. Regenerate the lockfile and the bundle:
   ```sh
   cd /home/sites/sugarcraft/candy-code-web && npm install --package-lock-only && npm ci
   cat package.json   # find the protocol-generation script (gen-protocol.mjs) and the build script names
   npm run <gen-protocol script>   # regenerates src-web/protocol/generated.ts from ../candy-code/docs/protocol/candy-code.v1.schema.json
   npm run build && npm run lint && npm run typecheck && npm test
   ```
   `dist/` is committed, and `web.yml` checks dist parity — commit the rebuilt `dist/`. Check that `src-web/protocol/client.ts` has
   `SUBPROTOCOL = 'candy-code.v1'`, and that `e2e/fixtures/rpc.ts` uses `candy-code.v1` and `candy-code.auth.`.
3. `candy-code` locates the bundle through the PHP shim (`SugarCraft\CodeWeb\…`) — run `candy-code`'s server tests and `php candy-code/bin/candy-code serve --help`.
4. `.github/workflows/web.yml`: paths `sugar-crush-web/**`, `working-directory`, `node-version-file`, and the job name → R3. Validate the YAML.
5. Procedure R5–R9.

**Done when:** both entries are `packagist-done`, CI (incl. the web workflow) and sync are green, and these manual smoke tests pass:
- `cd /tmp && php /home/sites/sugarcraft/candy-code/bin/candy-code doctor` exits 0.
- `cd /tmp && SUGARCRUSH_MCP_DISABLE=1 php /home/sites/sugarcraft/candy-code/bin/candy-code --help` prints exactly one
  `env SUGARCRUSH_MCP_DISABLE is deprecated` line on STDERR, then the normal help, and exits 0.
- Migration smoke with a throwaway HOME:
  `H=$(mktemp -d) && mkdir -m 0700 $H/.sugar-crush && echo '{}' > $H/.sugar-crush/config.json && HOME=$H php /home/sites/sugarcraft/candy-code/bin/candy-code doctor; ls -la $H`
  prints `moved …/.sugar-crush -> …/.candy-code`, then `$H/.candy-code/config.json` exists and `$H/.sugar-crush` is gone. A second run prints no
  migration line.

---

## Phase 9 — Docs, website, naming rulebook, agent configs

The mechanical renames already rewrote slugs everywhere. This phase fixes the **prose and rules** that describe the naming scheme itself.

1. **`PROJECT_NAMES.md`:** rewrite the prefix rulebook: `candy-` = runnable apps/CLI tools, `sugar-` = libraries, `honey-` retired 2026-10 (one
   `@rename-keep` history line listing `honey-bounce → sugar-spring`, `honey-flap → candy-flappy`). Suffix rule: plain descriptive nouns (what it
   does), keep upstream names only when they're the well-known term (`top`, `tetris`). Update the prefix cheat sheet and every table row.
   Add a "Renamed in 2026-10" table generated from `tools/renames.json` (old → new), marked `@rename-keep`.
2. **`docs/MATCHUPS.md`:** every `candy-*` row under `## Apps`, every `sugar-*` row under `## Libraries`. Update the "Naming conventions (cheat sheet)"
   section the same way as PROJECT_NAMES. Then `php tools/gen-docs.php` (patches the homepage counts) and `--check`.
3. **`docs/index.html`:** tiles in the right sections (Apps / Libraries), and tile descriptions read correctly with the new names. Hero copy mentioning
   "Candy / Sugar / Honey" → the new two-prefix scheme. `docs/js/search-index.json`: check it has no old slugs
   (`grep -oE '(candy|sugar|honey)-[a-z-]+' docs/js/search-index.json | sort -u`, compared against `ls -d */`). If a generator exists for it
   (`grep -rn search-index tools scripts`), run it, otherwise it was rewritten by R3 — verify. `docs/sitemap.xml`: only new slugs, and no stub pages.
   `docs/compare.html`, `docs/404.html`: verify.
4. **Root `README.md`:** package table grouped Apps / Libraries with the new names, and a short "Renamed packages (2026-10)" note pointing to PROJECT_NAMES.
5. **`CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`:**
   - `CLAUDE.md` Layout/“Foundation (candy-*) … Components/apps (sugar-*) … Physics (honey-*)” → "**Libraries** (`sugar-*`): … **Apps** (`candy-*`): …", with
     current names. Remove the "quirk: candy-core → SugarCraft\Core" sentence (sugar-core → `SugarCraft\Core` is no longer a quirk — the convention is
     now "namespace = descriptive suffix", e.g. `sugar-markdown` → `SugarCraft\Markdown`). The skeleton example `candy-focus/` → `sugar-focus/`. Fix
     the sugar-crush app layout paragraph (`candy-code/…`, `.candy-code/`).
   - `AGENTS.md` "Naming" section: same rewrite. "Foundation `candy-forms` is the form-primitives base that `sugar-bits` + `candy-shell` depend on" → "`sugar-forms` …
     `sugar-widgets` + `candy-scriptui` depend on". The façade paragraph examples (`sugar-bits`/`sugar-prompt` → `SugarCraft\Forms\*`) → `sugar-widgets` only (sugar-prompt is gone).
   - `CONTRIBUTING.md`: example paths (`candy-core/src/Util/Width.php`, `candy-shine`, `../candy-buffer`) were rewritten. Check the "Adding a new library" naming
     guidance says `Sugar*` for libs and `Candy*` for apps.
   - `CALIBER_LEARNINGS.md` (root and per-lib): rewritten by R3. Read the root file's naming-related bullets and fix any rule that's now wrong.
   (Remember Part A rule 2: don't run caliber.)
6. **`.opencode/`, `.agents/`, `.claude/`, `.cursor/` configs and skills** (e.g. `.claude/skills/*` mentioning `candy-*`/`sugar-*` paths): rewritten by R3. Grep for
   naming-rule prose (`grep -rn "honey-\|Honey-\|candy- foundation\|Candy- foundation" .opencode .agents .claude .cursor`) and fix it.
7. **Icons:** `scripts/generate_icons.py` `SUBJECTS` keys were rewritten. The icons are pictures without text, so no regeneration is needed. The monogram
   fallback (`_slug_accent`, keyed by prefix family) — check it has no `honey` family left and that `candy`/`sugar` still map to accents.
8. **`ansi/`:** `php tools/gen-ansi-readme.php --check` → 0. List art dirs whose wordmark still reads the old name in `prompt_kit/briefs/rename-followups.md`.
9. **`docs/repo_map.md`, `docs/research/INDEX.md`, `findings/README.md`, `findings/plan.md`:** link targets were renamed. Run a link check:
   `grep -rhoE '\]\(([^)#]+\.md)' docs/repo_map.md docs/research/INDEX.md findings/README.md findings/plan.md | sed -E 's/\]\(//' | while read p; do …; done` —
   resolve each path relative to its file and report missing targets. Fix them.
10. `php tools/check-rename-residue.php --all-done` → 0.

**Done when:** all of the above, plus the tools guards green. Commit: `docs: two-prefix naming scheme (candy- apps, sugar- libs)`. Push.

---

## Phase 10 — GitHub + Packagist finalization

1. **Archive the absorbed repos** (after pushing a "moved" README):
   `bash scripts/rename-remote.sh --archive candy-zone sugar-mouse` and
   `bash scripts/rename-remote.sh --archive sugar-prompt sugar-forms`.
2. **Repo metadata for all 61 packages:** for each dir with a `composer.json`:
   `gh repo edit sugarcraft/<slug> --description "<bootstrap DESCRIPTIONS value>" --homepage https://sugarcraft.github.io/lib/<slug>.html`
   and check that topics don't contain old slugs. Script it as a loop. Print a summary table; don't stop on one failure.
3. **Packagist abandon checklist (human, web UI only — there's no API):** `prompt_kit/briefs/rename-packagist-abandon-checklist.md` should list,
   per old package: the URL `https://packagist.org/packages/sugarcraft/<old>` → "Abandon" → replacement `sugarcraft/<final-new>`. Include
   `candy-zone` → `sugarcraft/sugar-mouse` and `sugar-prompt` → `sugarcraft/sugar-forms`. **Hand this list to the user** — the agent can't do it.
4. **Packagist verification for all new names:** loop `scripts/packagist.sh check <slug>` over every dir. Each must show `dev-master` at the latest
   split SHA (`git ls-remote https://github.com/sugarcraft/<slug> refs/heads/master`). For any stale one, run `scripts/packagist.sh update <slug>`.
5. **Org-level:** if `sugarcraft/.github` (org profile README) exists (`gh repo view sugarcraft/.github`), clone it, run the rename tool's rules by hand
   (or with `SUGARCRAFT_RENAME_ROOT`), commit and push. Same for any `sugarcraft/sugarcraft.github.io` content not generated from `docs/` (the
   sync-site job mirrors `docs/` — usually nothing extra).
6. **Monorepo repo** `detain/sugarcraft`: update the description/topics if they list old names: `gh repo view detain/sugarcraft --json description,repositoryTopics`.
7. **Codecov:** flags were renamed in `codecov.yml`. Old flag history doesn't carry over — accepted. Check the README badges point at the new flags
   (R3 did it). Nothing to do on codecov.io.
8. **Branch protection:** recheck required status checks (step 0.1 #5) against the new job names.

**Done when:** the archive and metadata loops have run, the checklist file is committed, and every new package resolves on Packagist. Commit:
`rename: remote finalization + Packagist abandon checklist`.

---

## Phase 11 — External consumers

General recipe for each repo: `cd` into it → `git status` clean → `git pull --ff-only` → apply the name map → `composer update` → its own tests/static
analysis → commit (author Joe Huss, no trailer) → push **only if that repo's normal workflow is push-to-master** (check `git log -5 --format='%an %s'`
and the remote. If unsure, commit locally and report to the user).

Old→new composer map (requires): use the B.3 table. The namespace map is the `ns_old → ns_new` columns. A handy one-shot for each repo is to
run the rename tool against it **in content-only mode**:
```sh
SUGARCRAFT_RENAME_ROOT=/path/to/consumer php /home/sites/sugarcraft/tools/rename-lib.php --old=<old> --no-move
```
for every entry the consumer touches (the tool must support a root without `<old>/` when `--no-move` is given). Then review the diff.
**Never rewrite the consumer's `vendor/`** (excluded already).

### 11.1 `/home/sites/webhooks.interserver.net`
- Requires (Packagist): `candy-input`, `sugar-table`, `sugar-dash`, `candy-fuzzy`, `sugar-toast`, `sugar-bits`, `candy-shine`
  → `sugar-input`, `sugar-table`, `sugar-dashboard`, `sugar-fuzzy`, `sugar-toast`, `sugar-widgets`, `sugar-markdown`.
- Namespaces used: `Dash`(9) → `Dashboard`, `Table`, `Forms`, `Sprinkles`(2) → `Style`, `Shine`(2) → `Markdown`, `Input`, `Toast`, `Core`.
  Note: it uses `SugarCraft\Sprinkles` without requiring `candy-sprinkles` (transitive). Add an explicit `"sugarcraft/sugar-style": "dev-master"` require.
- Prose: `src/Cli/Interactor/InteractiveTui.php` doc-comment lines 40–46, 120, 237; `scripts/github-code-review.php:296`.
- Then `composer update 'sugarcraft/*' --with-all-dependencies` and run its phpunit + phpstan (`phpstan.neon` exists).

### 11.2 `/home/sites/php-dup-finder`
- Uses **`type: vcs` repositories** for 27 sugarcraft repos (no Packagist). After the GitHub renames, the old URLs redirect, but each repo's
  composer.json now declares the **new** name, so `composer update` fails until both `repositories[].url` and `require` keys are updated.
  Rewrite every `https://github.com/sugarcraft/<old>` → `…/<new>`. Remove `candy-zone` (→ `sugar-mouse`, already present as candy-mouse→sugar-mouse)
  and `sugar-prompt` (→ `sugar-forms`). Add VCS entries for any new transitive packages Composer reports as missing (e.g. `sugar-animation` if
  `sugar-image` is pulled in). **Consider switching to plain Packagist** (delete the `repositories` block, keep `"canonical": true` default) —
  ask the user; don't decide alone.
- Requires: `candy-core, candy-zone, candy-hermit, candy-kit, candy-log, candy-palette, candy-sprinkles, sugar-bits, sugar-charts, sugar-crumbs, sugar-prompt,
  sugar-spark, sugar-stickers, sugar-table, sugar-toast, sugar-veil` → map them via B.3 (`sugar-spark` → `candy-ansi-inspect` is an **app** package now; check
  what php-dup-finder uses it for — if it uses library classes, it still works as a dependency).
- Namespaces used: `Core`, `Kit`(7) → `Cli`, `Sprinkles`(4) → `Style`, `Bits`(3) → `Widgets`, `Stickers`(2) → `Flexbox`, `Charts`.
- `box.json` lists `vendor/sugarcraft/<slug>/**` paths → update to the new slugs. Rebuild the phar (`php build-phar.php` — read it first) and
  smoke-test `bin/` help.
- Known pre-existing issues from earlier audits (memory: `Model::subscriptions()` missing, `php ^8.1` → `^8.3`). Fix them if they block the update, and tell the user.

### 11.3 `/home/sites/phlix/phlix-console-client`
- 16 Packagist requires + `candy-testing` dev → map via B.3 (`candy-focus` → `sugar-focus`, `candy-forms` → `sugar-forms`, `candy-hermit` → `sugar-quickpick`,
  `candy-mosaic` → `sugar-image`, `candy-palette` → `sugar-colorprofile`, `candy-shine` → `sugar-markdown`, `candy-sprinkles` → `sugar-style`, `sugar-boxer` → `sugar-panels`,
  `sugar-crumbs` → `sugar-navstack`, `sugar-reel` → `sugar-video`, `sugar-veil` → `sugar-overlay`, `candy-core` → `sugar-core`, `candy-fuzzy` → `sugar-fuzzy`,
  `candy-testing` → `sugar-testing`; `sugar-gallery`, `sugar-table`, `sugar-toast` unchanged).
- Heavy namespace use: `Core` 980 (unchanged), `Reel` 38 → `Video`, `Mosaic` 28 → `Image`, `Sprinkles` 29 → `Style`, `Veil` 17 → `Overlay`, `Boxer` 9 → `Panels`,
  `Palette` 5 → `ColorProfile`, `Hermit` 3 → `QuickPick`, `Shine` 2 → `Markdown`, `Crumbs` 2 → `NavStack`, `Zone` 1 → `Mouse\Zones`, `Flip` 1 → `Animation`,
  `Bounce` 1 → `Spring`, `Buffer` 1 → `CellBuffer`, `Layout` 1 → `Constraints`, `Ansi` 1 → `AnsiParser`.
  **Not ours:** `Phlix\Console\SugarCraft\Screen\*` and `SugarCraft\Chrome` are phlix's own namespaces. R1 only matches `SugarCraft\<ns_old>` directly
  after `SugarCraft\`, so `Phlix\Console\SugarCraft\Screen` is safe — verify in the diff anyway.
- Prose/CI: `.github/workflows/ci.yml` comment (`sugar-reel`'s sixel2png), `docs/vendor-patches.md` (patch file naming like `sugar-gallery-PosterCard.php.patch`
  — if patches exist for renamed packages, rename the patch files and their target paths), `src/**` doc-comments. Leave `CHANGELOG.md` history as is,
  and add a new changelog entry for the dependency rename. `build/phlix.phar` is a build artifact — rebuild it if the repo commits it (check `git ls-files build/`).
- Then `composer update 'sugarcraft/*' --with-all-dependencies`, PHPStan (`phpstan.neon`, L9), phpunit (`phpunit.xml.dist`), `php bin/phlix --help`.
  Earlier audits noted 12 pre-existing `PlayerScreenTest` failures — compare against a pre-change run of that repo, not against zero.

### 11.4 `/home/sites/provirted` (not in the user's list, but a known consumer)
- Its main `composer.json` has no sugarcraft. The TUI uses a **separate `composer-tui.json` → `vendor-tui/`** phar bundle requiring `candy-core`, `candy-forms`,
  `candy-sprinkles` (+ transitive) → `sugar-core`, `sugar-forms`, `sugar-style`. Update `composer-tui.json` (and its VCS/Packagist repo entries if any),
  `SugarCraft\Sprinkles` → `SugarCraft\Style` in its TUI sources, run `COMPOSER=composer-tui.json composer update`, rebuild the phar the way its README/scripts
  describe, and smoke-test `history tui`. If anything is unclear about how it's built, **stop and ask** — it's PHP-7.4-locked outside the TUI.

### 11.5 Sweep for unknown consumers
```sh
cd /home/sites && find . -maxdepth 4 -name composer.json -not -path '*/vendor/*' -not -path '*/node_modules/*' -not -path './sugarcraft/*' -print0 \
  | xargs -0 grep -l '"sugarcraft/' 2>/dev/null
```
(Run it in the background; it can take minutes.) Report any repo not covered above to the user. Don't modify repos you weren't asked to.

---

## Phase 12 — Final verification and cleanup

1. `cd /home/sites/sugarcraft && php tools/check-rename-residue.php --all-done` → 0.
2. `ls -d */ | grep -E '^honey-'` → empty. Every dir with a `composer.json` starts with `candy-` (and its composer `type` is `project`, or it has a `bin`)
   or `sugar-` (and it's a library). Write this as a test, `tools/tests/PrefixPolicyTest.php`, so it's enforced from now on: `candy-*` must declare `bin`
   or be `type: project`; `sugar-*` must not be `type: project`. Exceptions: `candy-code-web` is a lib by kind but carries the app's name — name it in the
   test with the reason ("asset bundle shipped with candy-code"). `candy-starter` has `bin/start` — OK.
3. Full local suite run of every lib (as in step 0.1 #3) vs the baseline. Every lib's figures must be ≥ the baseline (tests were added, none removed,
   except sugar-prompt's AliasResolutionTest and candy-zone's tests, which moved).
4. All tools guards (step 0.1 #4) → 0. `php tools/check-path-repos.php --strict-closure` after `--fix` → 0, then `git checkout -- '*/composer.json'`.
5. GitHub: `gh run list -R detain/sugarcraft -L 10` — the last CI, sync, vhs, web and pages runs are green. https://sugarcraft.github.io/lib/candy-shine.html
   redirects to `sugar-markdown.html` (curl it: `curl -sL -o /dev/null -w '%{url_effective}\n' …` — meta refresh won't follow in curl, so just grep the stub).
6. Packagist: every new package has a current `dev-master`. Old packages are on the abandon checklist (human).
7. Externals: each one is green, or its blocker is documented.
8. Remove scratch files listed in briefs if they're not useful. **Keep** `tools/renames.json` (the sync history and redirect stubs depend on it) and both tools.
9. Update the tracker at the top of this file. Final commit: `rename: complete`.

---

## Appendix 1 — Where names live (inventory, measured 2026-10-14)

So you know what the tool is expected to hit (counts are approximate, from `git ls-files`, excluding binaries):
- PSR-4 namespaces: one per lib, `SugarCraft\<Seg>\` → `src/`. Exceptions: `candy-mold` (`App\`), `candy-serve` (+ `\SSH\`, `\Git\`, `\LFS\`), `sugar-stickers` (+ `\Flex\`, `\Table\`).
- PascalCase brands in docs/READMEs: `SugarCrush` 198, `SugarBoxer` 175 (also a class), `CandyShine` 140, `CandyServe` 127, `SugarToast` 88 (unchanged lib), `CandyWish` 80, …
- sugar-crush user state: `~/.sugar-crush/` (HOME-rooted config dir — config, hooks, trust pins, policy snapshot, session.db, server/, caches,
  skills/rules/commands/agents/teams), `~/.config/sugarcraft-crush/`, `~/.local/share/sugar-crush/`, `<project>/.sugar-crush/` — see Phase 8A item 2.
- Env prefixes: `SUGARCRUSH_` ~1823 refs / 98 distinct vars, `SUGAR_CRUSH_` ~60, `CANDYSHELL_` ~47, `CANDY_SERVE_` ~44, `CANDY_PTY_` ~23, `SUGARTICK_`, `SUGARGLOW_`,
  `SUGARSPARK_`, `SUGAR_REEL_`, `CANDY_MINES_`, `CANDY_ZONE_`. `CANDY_TOP_*` (unchanged lib) and `SUGARCRAFT_*` (brand) are **not** renamed.
  `CANDY_RIM`, `CANDY_HUES`, `CANDY_AT`, `CANDY_SHADE`, `CANDY_BOLD`, `CANDY_FIT` are candy-top palette constants, not env prefixes — the tool never matches them
  (no entry has prefix `CANDY_`).
- Per-slug satellite files outside lib dirs: `docs/_data/<slug>.{json,body.html}` (62), `docs/lib/<slug>.html` (62), `media/icons/<slug>.png` (62),
  `docs/img/icons/<slug>.png` (62), `findings/<slug>.md` + `plan_<slug>.md` (58 each), `docs/repo_map/{,update_}sugarcraft_<slug>.md` (46 each),
  `docs/research/libraries/<slug>-research.md` (43), `ansi/<slug>/` art dirs (19 libs), `docs/admin/*`, `docs/dev/*`.
- CI/tooling with hardcoded slugs: `.github/workflows/{ci,vhs,vhs-smoke-test,pty-matrix,web,sync-sugarcraft}.yml`, `codecov.yml` (~348 refs),
  `scripts/affected-libs.php` (`WINDOWS_LIBS`, `MACOS_LIBS`), `scripts/bootstrap-org-repos.sh` (DESCRIPTIONS), `scripts/parallel-tests.sh` + `parallel-tests-durations.tsv`,
  `tools/check-child-lifetimes.php` (allowlisted spawn sites), `tools/check-path-repos.php` (comments), root `composer.json` (61 requires, 62 path repos) + `composer.lock`.
  Lib discovery itself is by `*/composer.json` glob, **not** by prefix, so the tools keep working after the renames.
- Intra-monorepo consumers with the largest blast radius (namespace refs from other libs): `candy-core` (sugar-crush 1130, sugar-dash 997, candy-top 367, candy-forms 300,
  candy-testing 171, candy-query 167, candy-vcr 149, sugar-bits 146, …), `candy-sprinkles` (sugar-crush 71, candy-shine 31, sugar-bits 29, candy-query 27, sugar-dash 27,
  sugar-charts 24, …), `candy-forms` (sugar-crush 135, sugar-bits 77, sugar-prompt 63, candy-shell 19, candy-query 14), `candy-vt` (candy-vcr 116), `candy-mouse`
  (sugar-crush 71, candy-zone 25), `sugar-mcp` (sugar-crush 62), `candy-mosaic` (sugar-crush 58, sugar-reel 18), `sugar-dash` (candy-top 21, candy-query 17), `candy-layout`
  (sugar-crush 40, candy-sprinkles 25). **candy-top, candy-query and sugar-crush** consume the most sibling libs. The tool updates them in the same commit as each rename,
  and Procedure R6 #2 re-runs their suites every time a dependency is renamed.

## Appendix 2 — Rollback

- A local rename that's not pushed: `git reset --hard origin/master` (only if there's nothing else unpushed — check `git log origin/master..`).
- A pushed rename with a broken CI: fix forward. Don't revert a rename after its GitHub repo was renamed (the split push would then target the old name
  through the redirect, with a mismatched composer name).
- GitHub repo renamed but the monorepo commit not pushed yet: either push the commit, or `gh repo rename <old> -R sugarcraft/<new> --yes` to undo.
