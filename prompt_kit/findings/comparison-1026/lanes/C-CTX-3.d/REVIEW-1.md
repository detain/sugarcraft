# C-CTX-3.d — REVIEW-1 (round 1)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 3 NIT. Otherwise approve-worthy; single cure below is a test-completion one.

Verified on e08dddd3e (detain A/C, zero trailers, tree clean, 6 files = brief-declared scope exactly; SessionSearchIndex/CompactionJournal edits purely additive inside declared regions).

Gates re-run by reviewer (cwd=candy-code, -c phpunit.xml, plain pipe):
- MemoryRecallSourcesTest: "Tests: 11, Assertions: 60" green; Deprecations 2 = tree baseline (same 2 in MemoryToolTest/drift filters).
- Full claimed battery filter: "Tests: 249, Assertions: 4770, Failures: 1" — the 1F is DreamPassTest:288 (no-backend Chat schedules a Closure). Foreign-proven: diff touches nothing on that path (asserts on `new Chat(backend: null)->update()`); last Chat.php/DreamPassTest changes 74acd2472/585fe0201 both in base; matches builder's revert-cp claim.
- ToolDocDriftTest 5T, ReadmeRosterDrift|EnvRoster|TrustKey 59T/39856A, MemoryDocumentationDrift|DocFigureProseDrift|SessionSearch 128T — ALL green (foldRanked extraction behavior-preserving).
- gen-tool-docs --check NOT re-runnable (php-script execution denied in reviewer permission map); its PHPUnit twin ToolDocDriftTest green is the equivalent evidence. README diff is one line inside the tools:roster generated region; MEMORY.md row present, DocFigure green.

Security core:
1. Root scoping: recallSessions refuses null/'' root BEFORE any open; $root is catalog-supplied raw cwd (model has no path to set it); `owner.cwd = ?` exact match mirrors latestResumableSessionScoped law. searchScoped(cwd=null) widens but no caller passes null. PASS.
2. Exclusion: real SQL `session_id <> ?`, pinned by pair (bound excludes cur-sess; unbound KEEPS it) — non-vacuous by construction. PASS.
3. SecretRedactor: separate pins per leg (sessions transcript sk-… → [REDACTED]; journal record sk-… → [REDACTED]); redact-then-clip order right. PASS.
4. Untrusted framing: header sentences match BoardReadTool's verbatim idiom. PASS.
5. Budgets: 6000B total pinned exactly (15×3000 → e14/e15, sum ≤6000). NOT pinned at edges: MAX_SESSION_HITS=8 (3 sessions seeded), MAX_SESSION_SNIPPET_BYTES=600 (short snippets), VIEW_MAX_ENTRIES=10 count cap (budget clips to 2 first — mutating the cap to unlimited keeps the test green). → MINOR (below).
6. openForReadOnly: is_file guard (never creates DB), DDL IF-NOT-EXISTS only, needsRebuild answers "not built yet" (pinned: no-marker store with rows, and fresh-sandbox leg); markBuilt unreachable from read path; in-memory dirty never persisted. PASS.
7. Journal: entries()/scanFrom/trimBefore untouched — pure-additive view; dream cursor safe. `compaction` field is engine minted (CompactionService:1261), small, unredacted-but-not-model-text: acceptable.

Reachability: EngineBackend.php:3321-3328 binds withShellOwner($this->sessionId) on every AcceptsShellOwner tool per turn (C-TOOL-3.c machinery Bash/ShellOutput already ride); AppBuilder/BackgroundSessionRunner withSessionId chain live; fromCatalog carries $context->root. Not test-only. PASS.
Byte-identity: zero pre-existing test files modified; no-store refusal + notes pins green in MemoryToolTest/DreamMemoryView. PASS. §1.9 literals clean.

MINOR (step-local): MemoryRecallSourcesTest — cap edges unpinned though the wave claim says "600B x8 / … budgets pinned at edges". Cure: (a) seed 9 zebrafish sessions in rootA, assert exactly 8 '- [' lines; one with a >700B body, assert its row ends '...' and strlen ≤ 600+marker; (b) append 11 tiny (≤50B) journal entries, assert count($view) === VIEW_MAX_ENTRIES and e11 present, e1 absent.

NIT-1: withShellOwner docblock "every launch-built … caller" reads as if launches leave exclusion off; engine binds per turn — reword at weld or next fix round.
NIT-2: REPORT says refusal "pinned in 5 test files"; grep finds 4 (MemoryToolTest, MemoryPromptWiringTest, BaseSystemPromptTest, new file) — claim numeral loose, all pins green regardless.
NIT-3 (welder carry, not lane's): TaskTool:1461 fresh `new MemoryTool(forAgent…)` drops root → delegated sessions-recall refuses; one-line follow-up owed by TaskTool owner per scope law (REPORT carry #3, correct call).

Welder carries confirmed: durations row MemoryRecallSourcesTest (+11T/+60A), §4 mark e08dddd3e, DreamPass:288 foreign red.
