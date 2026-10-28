# C-WEB-7.c — Round-1 review (W13: paged session.export + memoized non-git workspace walk)

REVIEWER: cc-cmp step reviewer (read-only round-1). Branch `cc/C-WEB-7.c` @ `82ad9c081`, base `5ed26cf92`.

## VERDICT: CLEAN (3 NITs; zero CRITICAL/MAJOR/MINOR)

---

## 0. Hygiene (disk-verified)

- `git -C /home/sites/cc-cmp-C-WEB-7.c branch --show-current` = `cc/C-WEB-7.c` ✓.
- `git log --oneline 5ed26cf92..HEAD` = ONE commit `82ad9c081` ✓ (base matches lead's stated 5ed26cf92).
- `git diff --stat`: 13 files, +951/−79 ✓ — matches the lead's figures exactly.
- Author law: `%ae` grep-c detain = 1, `%ce` grep-c detain = 1 (separate greps), zero `Co-Authored` trailers, plain subject ✓.
- `git status --porcelain` clean apart from untracked `node_modules` (gitignored-adjacent env dir) ✓.
- Forbidden-file check on the commit file list: `schema.json`, `generated.ts`, `suite-figure.json`, `durations.tsv`, `dist/` all ABSENT ✓ (§1.8 regen-then-restore trio honored — schema/generated restored; SERVER.md is the only root-doc change, see §4).
- No new `proc_open`/`popen`/`exec(`/fork sites in either touched `src/` file (the `fork` hits in SessionMethods are the semantic `session.fork` method, not process spawning; the lane touches no `ProcessContainment` surface) ✓.
- E646: no wall-clock/blanket timeout introduced anywhere on the export path; the walk's `TTL_SECONDS` is memo-expiry on an injectable clock seam, not a request timeout ✓.
- Dormant-code law: `session.export` was already live with production callers; the lane widens its params/result and the store keeps its `exportTranscript(sessionId, format)` signature — `SessionSidebar.vue` untouched ✓. No new method → no dormant-roster row needed.
- Scope: every file in the diff is named by the design block (plan_comparison.md §C-WEB-7.c files-touch + `design-web7.md`), and the hub file `MethodSchemas.php` is edited strictly inside the `session.export` rows ✓. No out-of-scope edit found.

Note: the lane dir held only `REPORT.md`; `BRIEF.md` was absent. Design conformance was judged against the authoritative design blocks (plan §1/§2/§6 laws, `design-web7.md` §C-WEB-7.c, §1.8). Recorded as informational NIT-2.

## 1. Design conformance — PASS

**Export paging.** `EXPORT_MIN_PAGE_BYTES = 65_536` / `EXPORT_MAX_PAGE_BYTES = 1_048_576`; `EXPORT_FRAME_HEADROOM_BYTES = 65_536`; private `EXPORT_REVISION_MAX_CHARS = 64` — all match the design. `limit` absent → early return of the legacy `{format, content}` answer byte-unchanged (the unpaged `too_large` floor at `Dispatcher`'s `MAX_SERVER_FRAME_BYTES = 4_194_304` guard still applies; the new paged tests pin both sides). Paged shape `{format, content, offset, nextOffset, total, more, revision}` exactly as designed.

**Revision.** `revision = hash('xxh128', $text)` over the export text; a caller-supplied revision that no longer matches raises `RpcError::of(ErrorCode::Conflict, …, 'export_changed')` — verified the kind string flows to the web through `jsonrpc.ts` (`ProtocolError.kind`, :30) and the store's restart-on-conflict catch. `revision` longer than 64 chars is refused `invalid_params` (Params::string maxLength throws) ✓.

**Character-safe cut.** `backToLeadByte()` walks back while `(ord & 0xC0) === 0x80` (continuation byte), applied to the raw-byte cut before `mb_strcut`; the paged test pins a 4-byte-emoji boundary landing mid-codepoint and asserts the page carries whole clusters and `nextOffset` equals the next page's `offset` exactly. Clamped cut is server-derived: client `offset` is honored, `nextOffset` is computed server-side from the actual cut length (`$start + strlen($cut)`) — the design's "echo back actual next offset" law holds (the client's `offset < page.nextOffset` guard is belt, not the only brake).

**Frame-headroom shrink.** Halving loop `while (strlen(JsonRpc::encode(['c' => $slice])) > 4_194_304 − 65_536)`; the probe uses a 1-byte key, the real envelope carries `content`/`sessionId` + JSON escape overhead well under the 64 KiB headroom. The control-byte test (all `0x00–0x1F`, ~6× escape blow-up) pins the shrink actually bites. Honest bounds, no magic.

**Params.** `offset` = `optionalInt` lower-bounded 0 (negative → refused, pinned in the wire test and confirmed `Params::int()` throws on out-of-range); `limit` = `optionalInt` in [65_536, 1_048_576] (below/above both refused `invalid_params`, pinned).

**Walk memoization.** `WorkspaceWalk` (final, private ctor, `src/Attachments`, single type per file): `MAX_ENTRIES 20_000`, `MAX_DEPTH 24`, `TTL_SECONDS 2.0`, `MAX_ROOTS 8`, memo keyed `realpath|showHidden`, insertion-order `array_shift` eviction, `forget(?root)` drops both `|0` and `|1` keys, `setClockForTesting` seam. `collect()` was moved **verbatim** from `FsMethods` (diff confirms: per-frame `ContainedPath::within` re-check, `@\opendir`, `is_link` skip, depth cap, dot/hidden rules all identical to base — base's `diff` of the two bodies is empty apart from the enclosing class). `FsMethods::walk()` now consumes the memo: `foreach (listing())`, needle screen, `break` at `WALK_MAX_CANDIDATES (5000)`, `sortedRelative` — since traversal order is preserved by the memo, the first-N-matches-in-walk-order equivalence with base holds; the old `WALK_MAX_ENTRIES`/`WALK_MAX_DEPTH` constants deleted from `FsMethods` with no dangling references.

## 2. Containment / trust law — PASS

- **Root stays trusted.** The only caller passes `FilesMethods::root($call)` — the same allowlisted per-session root FsSearch already used; the lane does not widen the root source and `WorkspaceWalk` is documented as taking only a resolved root.
- **Same law as FsSearch.** The base containment idiom is: root is the jail; every child path is re-checked `ContainedPath::within($root, $path)` per frame; symlinks are `is_link`-skipped (never followed); depth and entry caps bound the walk. All four legs are carried verbatim. The `@opendir` suppression stays `@`-scoped.
- **Symlink handling pinned.** `WorkspaceWalkTest` plants a symlinked directory pointing outside the root and asserts the EXACT listing `['keep/real.txt']` — any walk that followed the link admits `keep/dirlink/elsewhere.txt` and reddens the set equality. The escape attempt is a real test, not decoration.
- **Containment re-check is defense-in-depth.** With frames constructed only from inside the jail, removing the `within()` re-check is behavior-unreachable — exactly the same shape as base. The in-file comment says so honestly; it is not claimed as load-bearing. (The structural census still polices its presence, see §3.)
- **Export page bounds honest; no blowup.** Unpaged legacy stays capped (4 MiB `too_large`); the paged path never serializes the full transcript into one frame — it re-reads the stored transcript per page and slices. `nextOffset ≤ offset` is structurally impossible on advancing pages because the client guard would trip first (the client `if (page.nextOffset <= page.offset) throw 'without advancing'` is wired); `mb_strcut` guarantees ≥1-byte progress and the boundary test pins it.
- **Nothing newly world-readable.** Zero new file writes anywhere in the lane; the walk is read-only and the memo is in-process.

## 3. In-step guard honesty — PASS

- **ReadPathCensus (opendir FsMethods→WorkspaceWalk swap).** The ledger↔derived comparison at `ReadPathCensusTest.php:1207–1218` is `assertSame($ledger, $derived)` over a full `src/` walk, i.e. **bidirectional** — a missing ledger row for the new site and a stale ledger row for the removed site both redden. The commit adds the `WorkspaceWalk.php::collect` opendir row and drops the `FsMethods.php` row in the same commit; the census derives identically after the swap, so a drop-row-without-swap mutation would redden the assertSame. Structurally load-bearing.
- **ContainedPathInventory `ROUTED_CALL_SITES` swap.** The guard `assertSame` at :350 plus the count/sum arms at :395 (derived totals cross-checked against `ContainedPath`'s docblock figures). Verified by running the guard at tip: it FAILS with exactly 2 reds whose diff names ONLY the five foreign files (`Memory/AgentMemory.php` +1, `Skills/SkillLoader.php` 3→4, `Host/Commands/ContextHostCommand.php` +1, `Host/TranscriptProjector.php` +1, `Tools/PathJail.php` 5→6) — **neither** `FsMethods.php` nor `WorkspaceWalk.php` appears in the diff, proving the swap is exact and count/sum-neutral (1↔1). The same two tests run at the base (main tree @ 5ed26cf92) with the identical 49T/83A figures and the identical foreign diff → the reds are base-inherited, not lane-introduced (the disclosed foreign pair confirmed).
- **DocFigureProseDrift `thirty-seven` word-map.** Builder claims base-inheritance; re-proved: tip guard filter `DocFigureProseDrift|GlobDialect|DuplicatedTestHelperDrift|ChildWallClock` = 130T/16094A with exactly the one `thirty-seven` failure; the **same filter on the main tree at base 5ed26cf92** = 130T/16079A with the same single failure. Base-inherited ✓ (lane touched no doc that arm reads). Builder's REPORT quoted "129T/16091A" for that filter — off by one test / three assertions on both trees, a prose miscount (NIT-1), not drift.
- **§1.8 lanes.** Generated surfaces (`schema.json`, `generated.ts`) correctly restored out of the commit; durations rows and suite-figure/README re-pin are weld-owned; nothing weld-only was committed.

## 4. SERVER.md hand-edit — PASS

`git show --stat`: SERVER.md is 1 hunk, +2/−1 (the `maxServerFrameBytes` limits row, :378 area). Checked it against the generator, not just the design text: `ProtocolSchema::renderServerDoc` only rewrites between the `<!-- protocol:methods:begin/end -->` (:426–:500) and `events` (:530–:568) markers — the builder's hunk sits in the limits table OUTSIDE both marker regions, so the weld regen cannot collide with or be contradicted by it (the method-region rows in the committed file are HEAD-pristine, confirmed byte-wise). `ServerProtocolDocumentationDriftTest` (green inside the reproduced builder filter) asserts only the `| \`maxServerFrameBytes\` | 4 MiB` prefix, which the hunk preserves. The design's `files.modify` list explicitly sanctions this exact hand-edit. Direct `gen-protocol-schema --write` execution was impossible under the reviewer's bash map (no cp/redirections) — the marker-region derivation above is the substitute proof; either way the residual delta is weld-regen-owned and structurally non-conflicting.

## 5. Gate reproduction — all match

Plain pipe, `env -u LINES -u COLUMNS`, cwd `candy-code`, every filter shaped so `PtyRunToolTest` never matches:

- Builder filter 1 → **63T/1098A, 1F = `ProtocolSchemaDriftTest` only** (the by-design stale-schema trio; failure output shows the missing `session.export` page fields — exactly the weld-regen artifact) — EXACT match to the builder's claim. `ReadPathCensus`, `ServerProtocolDocumentationDrift`, `FsSearch`, `SnapshotPaging`, `ToolMethods` green inside.
- Guard filter → **130T/16094A, 1F** = `thirty-seven` word-map (base-proven above). Builder said 129T/16091A → NIT-1.
- New files alone → **17T/79A OK** (SessionExportPaging 10T/61A + WorkspaceWalk 7T/18A) ✓.
- Citation/docblock/home-s/swallow-census family → **20T/16950A OK** (new `@see MentionIndex` cites resolve via same-namespace; `SymbolCitationDriftTest` was not in the builder's list — I ran it, green).
- Vitest serial `--no-file-parallelism` over the 3 named specs → **3 files / 16 tests passed** ✓ (sessionsExportPaging + sessionsRowActions + sidebarActions; the `exec(` hits are test-side fixtures only).
- Foreign standing (report-only, all base-proven not-lane): `ReadmeSuiteFigureDriftTest` staleness pair (lane +17T, weld-owned figure), `thirty-seven` DocFigure arm, `ContainedPathInventory` ×2.

## 6. Non-vacuity — reasoning PASS

- **Paging bound off-by-one** (`nextOffset = start + strlen(cut) ± 1`): breaks both the byte-identical reassembly `assertSame` and the exact `offset`/`nextOffset` pins; the `walkExport` loop's monotonic guard also makes a backward off-by-one a loud client throw.
- **`more` boundary** (`<` → `≤`): an over-long final page yields a phantom empty continuation → reassembly/content equality breaks (or the walk loops past `total` and the exact page-count assertion reddens).
- **`backToLeadByte` neutered**: mid-codepoint boundary test asserts the exact continuation byte at `nextOffset` → red.
- **Revision guard neutered**: stale-revision test expects `ErrorCode::Conflict`/`export_changed` → red. **Shrink loop neutered**: control-byte test asserts the encoded frame ≤ budget AND that the cut was shrunk → red.
- **Walk escape**: removing the `is_link` skip walks the planted directory link → the EXACT-listing `assertSame(['keep/real.txt'])` reddens. Depth cap pinned both sides (d23 listed, d24 absent). TTL/memo/eviction/forget tests drive the clock seam and assert exact listings (20.1s/2.1s-style staleness boundaries and the 9-roots→8-kept eviction pin each name their mechanism).
- **Red-before claim at base (8 errors)**: mechanistically consistent — `Schema::object` defaults `open=true`, so paged params validate at base; base `export()` ignores them and answers `{format,content}`, and each paged key read in the 8 key-touching tests is an undefined-array-key PHP warning that `failOnWarning="true"` promotes to error (the shape/too_large tests stay green) — matching the builder's 8/10 split exactly. Could not execute the swap under the reviewer bash map; the derivation plus the builder's reported cmp-verified restore is accepted.
- **Web side**: `ProtocolError.kind` exists (`jsonrpc.ts:30`); `sessionsExportPaging.spec.ts` drives the real store reducer path for restart-once (`export_changed` resets parts/offset/revision, second conflict rethrows), the no-advance throw, and the `more=false` join. The `MethodResult<'session.export'>` type is locally widened with an `ExportPage` cast pending weld regen — weld-regen makes it redundant but harmless, and the store signature kept means no sidebar churn.

## Findings

- **NIT-1** (step-local): `REPORT.md` guard-filter prose says 129T/16091A; measured 130T/16094A at tip (and 130T at base). Cosmetic count in a prose sentence; no code/test impact. Fix: the weld may note the corrected figure when stamping the closeout.
- **NIT-2** (informational): lane dir shipped without `BRIEF.md` (the lead's protocol asked reviewers to read it first); design was reconstructed from plan_comparison.md §C-WEB-7.c + design-web7.md.
- **NIT-3** (out-of-scope, record-only): `WorkspaceWalk`'s per-frame `ContainedPath::within` re-check is behavior-unreachable (faithfully moved from base; honestly commented as defense-in-depth). No action; the census already polices its presence.

## Weld duties confirmed owed (already surfaced by the lead)

2 durations rows (`SessionExportPagingTest.php`, `WorkspaceWalkTest.php`; the vitest spec gets NO row); suite-figure/README re-pin (+17T, staleness pair heals); regen trio `schema.json` / SERVER.md method-region / `generated.ts` (+ web dist rebuild); `thirty-seven` DocFigure word-map flip and the foreign `ContainedPathInventory` roster drift are NOT this lane's (base-inherited, other-program-owned).

**Conclusion: APPROVE / CLEAN at round 1.** All six verification categories pass on disk and in reproduction; zero CRITICAL/MAJOR/MINOR; three NIT disclosures above.
