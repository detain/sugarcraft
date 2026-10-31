# C-TUI-6.g — Web i18n: migrate the remaining components — BUILDER DONE

Branch `cc/C-TUI-6.g` @ /home/sites/cc-cmp-C-TUI-6.g, cut base `29929c305`. Master moved repeatedly under the lane (last observed `acc851ca3`, C-TOOL-6.c welder chain) — per brief: HOLD, never rebased; all 5 picks are cherry-pick-ready on the branch tip `f73ac4315`.

## Picks (all author/committer detain@interserver.net, 0 trailers, 0 dist/trio paths each)
1. `cfbbeffc5` views (login/dashboard/session) + SessionSidebar + AboutModal + HelpOverlay
2. `b9c7b9db6` Composer + QueueStrip + HistorySearch + DirectoryPicker (web.composer.*)
3. `d993a3904` transcript cluster: Transcript, TranscriptItem, ReasoningFold, ToolCard, DiffView, FileViewer, MediaCard, PermissionCard (web.transcript/ media/ approvals.)
4. `0685ff8d5` panels/* ×11 (web.panels.*, +143 keys)
5. `f73ac4315` agents×6 + approvals×2 + grid×3 + settings×3 (web.agents/ approvals/ grid/ settings., +114 keys; amended once to repair the empty-author prefix-assignment trap — final blob correct, greps below prove it)

## Component census (design file-list)
46 remaining .vue after 6.f. Migrated: 43 (this list ×5 above). Zero-chrome disclosures (untouched, verified no UI strings): SessionTools.vue, TodoPanel.vue, MessageMarkdown.vue (v-html only). 43+3 = 46/46 accounted.

## Catalogue
- lang/en.php: ONE contiguous `web.*` run between the 6.f markers, SORT_STRING, now 519 keys; php -l each commit.
- Both mirrors regenerate in-step every key commit: docs/protocol/web-strings.en.json + src-web/lib/strings.en.generated.ts.
- Protocol surface UNCHANGED (no new method/scope — server.strings already serves the web. prefix). Trio/schema/SERVER.md/generated.ts never touched; dist never staged (per-commit name-only grep = 0, above).
- i18nCoverage.spec end state: 47 files carry literal t('web.*') keys (floor >20), 492 distinct literal keys (floor >40), zero missing; 9 dynamic t(var) sites resolve via KEYMAP_LABELS/ACTION_LABELS key-string tables.

## Gate results (final HEAD)
- `php scripts/gen-web-strings.php --check` rc=0; `node scripts/gen-web-strings.mjs --check` rc=0.
- phpunit cwd=candy-code -c phpunit.xml env -u LINES -u COLUMNS:
  - `--filter WebStringsTest` → Tests: 7, Assertions: 549 OK (NON-ZERO).
  - `--filter WebStringsDriftTest` → Tests: 2, Assertions: 5 OK.
  - `--filter 'CliLangCatalogueTest|LangParityTest|DuplicatedTestHelperDriftTest|TreeWideGuardRosterTest'` → Tests: 46, Assertions: 8278 OK (expected-greens hold post-en.php edits).
- vitest serial (--no-file-parallelism): agents+approvals+grid+settings+i18nCoverage → 9 files / 45 tests GREEN; components/__tests__ + views/ → 31 files / 292 tests GREEN. Panels dir 18/152 GREEN at slice 4 (untouched since). English renders byte-identical — all existing specs pass unchanged, none edited (design DoD).
- PtyRunToolTest::testExpectMiss exclusion: not hit (filter-anchored runs only).

## Load-bearing evidence
- Mutation run: deleted `"web.agents.send"` line from strings.en.generated.ts → WebStringsDriftTest exactly 1F; cp-restore → green, git clean, blob md5 f4036169d8923ca68080aaee067ee2a7 (= committed).
- In-lane accidental proofs: slice-3 wrong key reference reddened MediaCard spec at commit-time (raw-key render); slice-4 v-if/v-else-if carry-over reddened contextPanel.spec 'says "not measured"'. Both fixed before commit — pins genuinely police the migration.
- No new test files created (design tests: none) → durations.tsv row minting = N/A; §7 list: no new *Test.php / *.spec.ts from this lane.

## Deviations / refused seams (disclosed)
- dictation.ts notices stay hard-coded (6.k-era .ts surface; design file-list is .vue-only; C-TUI-6.n owns Composer dictation+en.php keys later). Composer-internal dictation chrome migrated.
- .ts store vocabularies out of scope: stores/settings.ts NOTIFICATION_PROBLEM_TEXT / appliesLabel / provenance + tier refusal strings, attention.ts notification texts — .vue call-sites route their presentation chrome via t(); the .ts values themselves are server/config vocabulary per owner ruling (server text untranslated).
- Literal stays: wire option texts (queue/steer/interrupt), 'JSON', 'MCP', '/path/to/project' placeholder, doc names, listing paths, '7d'/'30d', proper nouns.
- ApprovalsButton has NO `aria-label="Approvals"` in the tree (stale pre-compression note) — real strings were :title ternary + `<span class="sr-only">open questions</span>`; migrated both, +1 key web.approvals.sr_open_questions (run 518→519).
- Expected WebStringsTest count is 7 (compressed note said 17 — live enumeration wins; non-zero sanity satisfied).

Welder notes: base has drifted — expect the 5 picks as a chain cherry (en.php/mirrors are additive-block regions, zero known overlap with src/Tools, src/Server, sugar-pty surfaces). dist rebuild + §7 figure work is weld/gate duty as always.
Lane clean: `git status --porcelain` = 0 at final HEAD.
