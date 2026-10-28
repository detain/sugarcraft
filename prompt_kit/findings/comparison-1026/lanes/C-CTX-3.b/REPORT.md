# C-CTX-3.b — '#' quick-add to personal instructions (TUI) — DONE

Commit `aa8e4fdbf` on `cc/C-CTX-3.b` (base 9fc915cd9, 6 files, +498/−1). Author+committer detain@interserver.net verified (separate greps ==1), zero Co-Authored trailers. Never pushed.

Shipped: single-line `#text` submit (second char not `#`) arms a uiOnly confirm row instead of a turn; Enter appends `- <note>` to `~/.candy-code/AGENTS.md` (PrivateDir 0700 dir, AtomicFileWriter 0600 on create, LOCK_EX append preserving existing bytes+mode, realpath-boundary + symlink refusal); Esc/any-other-key cancels and restores the draft byte-exact (other key re-routes). Multi-line, `##…`, bare `#`, and mid-turn submits never captured. Note reaches model via C-CTX-1.a InstructionDiffTracker (zero tracker edits — verified mechanism, not duplicated).

Files: src/Chat.php (ctor param + mutate/session re-lists + submit arm + quickAddNote/handleQuickAddKey/appendQuickAddNote), src/Chat/Handlers/HandlesKeys.php (owner arm after titleEditor), lang/en.php (chat.instructions.quick_add.{appended,confirm,refused}), docs/MEMORY.md (### Quick-add from the chat — disjoint from C-CTX-2.f), tests/Support/ContainedPathInventoryTest.php (handSpelled Chat.php 1→2 in-step), NEW tests/Chat/InstructionQuickAddTest.php.

Gates (cwd candy-code, plain pipe): InstructionQuickAddTest **11T/53A green**; ReadPathCensusTest green; DenialPrefixRosterTest green; BangShellTest 11T/74A; dispatch neighborhood 113T/1489A green; DocFigureProseDriftTest 92T/4056A green. Combined catalogue/inventory run 88T/898A/3F — all 3 **base-inherited**, proven red with lane edits reverted: ContainedPathInventory routed roster (Memory/AgentMemory.php ⇒1, Skills/SkillLoader.php 3→4), handSpelled legs (Host/Commands/ContextHostCommand.php +1, Host/TranscriptProjector.php +1, Tools/PathJail.php 5→6), ChatHostLangCatalogue 5 keys asked from dirs absent from its sources() (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.not_persisted, chat.permission.persisted). My legs pass every affected arm.

Mutation M1 (quickAddNote→null): exactly 7 capture-dependent reds, 4 pass-through pins green; cp-restore md5-verified.

Deviations: (1) plan's `[chat:key dispatch]` region physically lives in HandlesKeys.php trait since W7-S4 — one routing arm there, body in Chat.php per titleEditor precedent; (2) confirm refused-path row uses plain sentence (no `**Refused:**` shape — DenialPrefixRoster clean); (3) read-only-mode `#` behaves as prompt/refusal (arm sits after readOnlyRefusal — conservative).

Welder carries: NEW test file InstructionQuickAddTest.php needs scripts/parallel-tests-durations.tsv row + suite-figure re-pin (+11T/+53A live-enum). Base-inherited roster drifts above need their owning lanes' adjudication comments (not this lane's files).

---

## FIX ROUND 1 (2026-10-28) — commit b4245ba44 on aa8e4fdbf

Round-1 escalation verdict (relayed): 1 MAJOR (trust-door asymmetry) + 1 step-local
MINOR (consume-drain pin), rest clean. The earlier on-disk REVIEW-1.md (CLEAN pass) is
left canonical; these are the escalated findings. NOTE: a first-round fix commit
(79b6b96b8) claimed by the pre-grounding session never existed on disk (no object, no
dangling child of aa8e4fdbf, tree pristine) — the cure below was RE-EXECUTED from the
brief, not recovered.

**Path errata (disclosed, findings still cured at the lane-equivalent shapes):**
- Reviewer-cited `ContentEditor.php:102-116 validateFile` is a PHANTOM — no such file
  exists in the worktree, main tree, master, origin/master, or any cc/C-CTX-* branch,
  and `validateFile` appears nowhere in the repo. The lane's actual file-validation
  site is `Chat::appendQuickAddNote`; all MAJOR fixes land there.
- `InstructionTracker.php:347-351` likewise does not exist (InstructionDiffTracker.php
  belongs to C-CTX-1.a; the lane made zero tracker edits). Lane-equivalent = the
  consume in `handleQuickAddKey`; pinned below.

### MAJOR — containing-directory trust verdict
- src arm: `realpath($dir)` verdict widened to the reviewer's three-part shape —
  resolves, `is_dir($resolved)` (NEW), prefix-inside-owned-home. Docblock rewritten
  truthfully (incl. why the existing-target file link is the dangerous leg: `is_file`
  follows links, the APPEND rides through).
- New pins (InstructionQuickAddTest 11T→16T):
  1. `testAFileLinkToAnExistingTargetIsRefusedAndItsBytesStayUntouched` — the review's
     real repro (victim file, symlink at AGENTS.md path): refusal row, victim bytes
     byte-identical, link left as found. The pre-existing symlink pin used a
     dangling target, which could NOT discriminate an is_link neuter (write simply
     failed); this one can.
  2. `testADirectoryLinkOntoAFileIsRefusedAndTheFileIsUntouched` — fail-closed refusal,
     file bytes untouched.
  3. `testADanglingDirectoryLinkIsRefusedAndCreatesNothing` — no bytes behind the link,
     target not conjured (dangling shape, uid-independent, no root fragility).
  4. `testTheContainingDirectoryVerdictCarriesAllThreeArms` — structural source-slice
     pin: realpath/is_dir/str_starts_with/is_link each appear exactly once in the
     writer body.
- Mutation proofs (cp+md5-clean, plain pipe, filter InstructionQuickAdd):
  M1 strip is_link arm → 3 reds (both file-link behaviors + structural);
  M2 strip prefix arm → 2 reds (dir-out-of-home + structural);
  M3 strip is_dir($resolved) → 1 red (structural ONLY — honest disclosure: that arm
  is defense-in-depth/race policy, behaviorally absorbed by PrivateDir's lstat throw +
  realpath-false on every reachable single-threaded shape; it is pinned structurally
  because the reviewer's cure explicitly asked for the arm and its removal must not
  pass silently);
  M4 neuter consume ('pendingQuickAdd' => null removed from Enter branch) → 7 reds.
- Legitimacy leg: pre-existing append pins (fresh-create 0600, second-note append,
  existing-mode preserve) still green = the in-root case keeps writing.

### MINOR — consume-drain guard
`testAConsumedConfirmCannotReAddOnALaterEnter`: after Enter appends once, a second
Enter finds nothing to consume — file still exactly `- once only\n` (substr-equivalent
byte assert), pending stays null, backend calls 0 (no turn starts from the drained
row). Red under M4 (7-red collateral confirms the consume is load-bearing).

### Gates at b4245ba44 (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe)
- InstructionQuickAddTest 16T/77A OK.
- ReadPathCensus|DenialPrefixRoster|GlobDialect|DuplicatedTestHelperDrift: 64T OK
  (GlobDialect corpus figure unmoved — new literals are not glob-shaped).
- Instruction|QuickAdd|BangShell|HandlesKeys|SlashDispatch: 362T/2202A OK.
- DocFigureProseDriftTest 92T/4056A OK (docs untouched this round; MEMORY.md claim
  stays true and is now even more so).
- ReadmeSuiteFigureDrift|ChatHostLangCatalogue|ContainedPathInventory: 5F — ALL
  base-inherited, PROVEN: same 5 failures at aa8e4fdbf with my files reverted
  (live enum 27,370 there vs 27,375 here ⇒ lane delta exactly +5T; the pinned 25,537
  gap is foreign-era drift, welder §7 re-pin carries it, +5T rides the same duty).
  Chat.php appears in NEITHER inventory failure diff (both diffs foreign files only;
  is_dir is not an inventoried compare) ⇒ §1.8 roster holds, handSpelled Chat.php 2
  unchanged.
- "PHPUnit Deprecations: 2" = AccessorDeprecationTest providers, pre-existing (paired
  base re-run reproduces exactly), not failOn-gated.

### Welder carries (round 1 adds)
- durations.tsv row for InstructionQuickAddTest must reflect 16T (+5T vs round-0 note);
  suite-figure re-pin now +16T total from this lane (was +11T), live-enum delta
  +5T for this file's new methods.
- Foreign-era staleness pair (25,537 vs 27,375) and the ApplySettings/ClearBatch/
  ColdResume/ChatHostLangCatalogue/ContainedPathInventory base reds remain the
  owning lanes' / welder's adjudication duty as already listed in REVIEW-1.md.
