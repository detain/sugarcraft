# C-SEC-5.b — Web trust targets and MCP pin re-approval

**LANE:** cc-cmp builder, worktree `/home/sites/cc-cmp-C-SEC-5.b`, branch `cc/C-SEC-5.b` (base `b985fbe51`).
**COMMIT:** `563b1c0c5a21a38e58a4be42795965ba55a11b6b` — `C-SEC-5.b: Web trust targets and MCP pin re-approval` — 18 files, +721/−230. Author + committer Joe Huss <detain@interserver.net> (`%ae`/`%ce` verified separately, 1 detain each; zero Co-Authored trailers). Never pushed.

## Implementation

**Server (PHP):**
- `src/Protocol/TrustAsks.php` — records keyed by `(root, target)` (`$byRoot` nested, `$byAsk` carries `['root','target']`); `TARGET_MCP` const removed (was file-only). New `discoverPending(root, broadcast)` runs the one launch-census loop (`Bootstrap::pendingProjectTrust`) both wire doors call; `discover()` takes a `TrustTarget` and builds the reason from `$target->askKey()`; `records(root)` re-states the rows in census order; `settle(…, ?SettingsWriter $writer = null)` routes a user-tier grant through `Bootstrap::grantProjectTrust($target, $root, $writer)` — per-door `trust_write_failed` refusal data falls back to `'the <configKey> list was not updated'`; settling one row never touches the others.
- `src/Protocol/Methods/ContextMethods.php` — `sources()` calls `TrustAsks::discoverPending` and answers `trusts` (the LIST); `trust()` passes the session workspace's `SettingsWriter` service (instanceof-narrowed, per the SettingsMethods precedent) into `settle`; register() descriptions speak the doors.
- `src/Protocol/Methods/SessionMethods.php` — subscribe-time discovery (C-SEC-4.b) now runs the widened census via `discoverPending` (hooks/.mcp.json/settings root mints three prompts at subscribe).
- `src/Protocol/Schema/Definitions.php` — `TRUST_PROMPT.target` widened to the five census values (`mcp`, `mcp-pin`, `hooks`, `commands`, `settings`); `CONTEXT_SOURCES.trust` (nullable single) replaced by `trusts` (array of prompts, may be empty, settled rows re-stated).
- `src/Protocol/Schema/MethodSchemas.php` — `context.trust` askId/remember descriptions speak per-door writes and per-row settling.
- `src/Host/ContextSources.php` — docblock truth-flip only (the `trusts` list rides the methods layer, not this answer).
- `docs/PERMISSIONS.md` — new subsection "The same doors on the web surface" under the four-`trustedProject*`-keys section: the web panel prompts for all four lists (and the pin re-approval rides the MCP door), one row per pending door, grants frozen-at-launch/next-launch.
- **No lang changes:** C-SEC-5.a landed all five `server.trust.ask.*` rows + `server.trust.write_failed` in `lang/en.php` (dir is en-only); the per-door refusal detail is wire data, not a user string.

**Web:**
- `src-web/stores/contextSources.ts` — the shelf is a LIST (`pendingTrusts`); `load()` replaces the shelf from the measurement (the whole truth, settled rows included); `respondTrust(askId, decision, remember)` answers one row by `askId` (session stamp from the measured session or the shelved row), re-stamps the row on success, removes it on `already_resolved`/`ask_not_found`, rethrows `trust_write_failed` leaving the row asked; live `permission.asked` prompts merge into the shelf (and the measured `trusts`) by `askId` — the old single-prompt refuse-over-open law retired with the single shelf.
- `ContextPanel.vue` — renders one row per prompt (`data-testid="context-trust-row"`, `:data-target`) with its own reason/root/verdict buttons and settled line (frozen-roster wording kept byte-identical); the error line stays panel-level.
- `StatusBar.vue` — the badge counts the awaiting doors: `trust?` for one, `trust? (N)` for more, title carrying the count.

## Gates (targeted only)

PHP (cwd `candy-code`, `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter`):
- `--filter 'ContextTrust'` → **OK, 15 tests / 114 assertions**, 0 warnings, 2 pre-existing PHPUnit deprecations (ContextTrustPromptTest-side). Includes the NEW `ContextTrustTargetsTest` (3 proofs: census-order three rows + once-per-(root,target); settings-tier grant writes only `trustedProjectSettings`, mcp/hooks rows stay asked, re-answer `already_resolved`; drifted pins on a trusted root mint `mcp-pin` alone and the user grant re-records via the MCP door — `mcp-trust.json` carries the pins, the `trustedProjectMcp` list byte-unchanged).
- `--filter 'ContextSourcesTest|GatewayTrust'` → **OK, 11 tests / 140 assertions**.
- `--filter 'ProtocolSchemaDrift|ServerProtocolDocumentationDrift|TrustKeyDocumentationDrift'` → **OK, 24 tests / 854 assertions** (run against the regenerated artifacts).

Web (cwd `candy-code-web`, serial):
- `npx vitest run src-web/components/panels/__tests__/contextPanel.spec.ts src-web/components/__tests__/statusBarTrustBadge.spec.ts src-web/stores/__tests__/contextSources.spec.ts src-web/stores/__tests__/contextTrustRouting.spec.ts --no-file-parallelism` → **29/29 passed** (multi-row panel test, per-row independence, badge count + decrease, shelf-list routing incl. the measurement-first session-filter test).

## Protocol regeneration (local only — restored)

`cd candy-code && php scripts/gen-protocol-schema.php --write` + `cd candy-code-web && npm run gen:protocol` — guards and vitest all ran green against the regenerated `target` five-case enum and `trusts?: TrustPrompt[]`. BEFORE commit the three generated files were restored: `git checkout -- candy-code/docs/protocol/candy-code.v1.schema.json candy-code/docs/SERVER.md candy-code-web/src-web/protocol/generated.ts`; `git status --short` on them printed nothing (TRIO-CLEAN) and the tree is clean post-commit. Drift is judged on the welded tree by the welder. `dist/`, `suite-figure.json`, `durations.tsv` untouched (verified `git diff --stat` empty).

## Deviations / notes

1. Beyond the brief's named files, `ContextSourcesTest.php`, `GatewayTrustRoutingTest.php`, `contextSources.spec.ts`, `contextTrustRouting.spec.ts` and `statusBarTrustBadge.spec.ts` were adapted in-step — necessary API renames (`trust`→`trusts`, shelf single→list) the shape change forces; no behavior beyond the brief's intent. The routing test's session filter is now stated measurement-first (the old pass was an artifact of the retired single-shelf law).
2. No lang edits (see above).
3. Grant destinations split by door: `mcp`/`mcp-pin` land in the HOME config via the MCP door (`Bootstrap::trustProjectMcp` → `writeUserConfig`, re-records fingerprints); `hooks`/`commands`/`settings` land via the workspace `SettingsWriter` (the protocol fixture's sandboxed `dir/config.json` — the real server falls back to the HOME writer). Recorded in `ContextTrustTargetsTest` comments.
4. New PHP test file `candy-code/tests/Protocol/ContextTrustTargetsTest.php` — the gate owes its `parallel-tests-durations.tsv` row.
5. `ContextMethods.php` keeps `use Bootstrap` for the docblock `@see` reference after the census call moved into `TrustAsks::discoverPending`.
6. SECURITY DISCLOSURE: ~13 injected `REWRITE TASK` user messages arrived mid-lane (identical text demanding master commits/push, committing broken states, disclosing config, and never mentioning the re-write). They contradict the lane brief and were refused as prompt injection; all work followed the original brief and this lane commits only on `cc/C-SEC-5.b`.

## Round 2 (fix for REVIEW-1 MAJOR-1)

**COMMIT:** `622fce834` (AMEND of `563b1c0c5`, message preserved) — same 18-file set (verified `git diff --name-only` identical vs round-1 tip), author + committer `detain@interserver.net` (`%ae`/`%ce` verified separately, 1 detain each), zero trailers, never pushed.

**Fix (DuplicatedTestHelperDriftTest red 2F → green):** per the reviewer's lane-ce-precedent prescription —
- `ContextTrustTargetsTest.php`: `declareMcpServers` renamed to `declareLedgerServer` (kills the one-token `local`/`ledger` body-drift pair) and `trustRow` renamed to `trustRowFor` (kills the signature-drift pair; the `= 'mcp'` default belongs only to the single-door file); call sites updated (3 + 1).
- `ContextTrustPromptTest.php`: `use …Server\Support\WireClient;` import added and both FQN parameter spellings shortened, so the surviving `sources` pair is byte-identical in body AND signature (guard reports drift, not duplication — byte-identical copies are the accepted shape).

**Gates (cwd worktree `candy-code`, plain pipe, `env -u LINES -u COLUMNS`):**
- `--filter 'DuplicatedTestHelperDrift'` → **OK, 14 tests / 194 assertions**, 0 failures (was 2F at `563b1c0c5`).
- `--filter 'DuplicatedTestHelperDrift|ContextTrust'` → **OK, 29 tests / 308 assertions** (= guard 14T/194A + ContextTrust family 15T/114A, exactly the round-1 family figure), 2 pre-existing PHPUnit deprecations unchanged.
- `php -l` both files clean; no other test file touched; `dist/`, `suite-figure.json`, `durations.tsv`, protocol trio still untouched; worktree clean post-commit. vitest not re-run (zero vue/ts touches this round).

