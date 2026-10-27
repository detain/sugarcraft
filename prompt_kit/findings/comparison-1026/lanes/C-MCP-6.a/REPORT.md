# C-MCP-6.a — MCP hygiene (three legs) — CLOSED

Commit `18d163ccd` on `cc/C-MCP-6.a` (base c36cc1982), worktree /home/sites/cc-cmp-C-MCP-6.a, tree clean, NEVER pushed. Author/committer detain@interserver.net verified via SEPARATE %ae/%ce greps; zero Co-Authored trailers; plain -m.

## Files
- src/Support/McpLoginFork.php — leg 1
- src/Tools/BuiltIn/McpListTool.php — leg 2
- src/MCP/SseMcpServer.php — leg 3
- tests/Chat/McpLoginForkTest.php — leg-1 pins
- tests/MCP/McpDispatcherToolsTest.php — leg-2 pins
- tests/MCP/SseMcpServerForkTlsTest.php — NEW (leg-3 pins)
- docs/MCP.md — §Deferred schemas mcp_list bullet ONLY (minimal hunk, no numerals)

## Leg 1: login-URL scan
SCAN_MAX_BYTES=65536 flood-bound scan buffer, head-trimmed and stopped at the
announce — the authorize URL (2 KB+) can no longer scroll out of a window
before its line start is read. Announce regex is now `~^\s+(https?://\S+)\s*$~m`
over the scan: ONLY a line consisting of nothing but one URL, the exact
OAuthLoopbackFlow.php:158-160 print shape; the backticked SERVER-URL sentence
line can never be announced again. NEW static redirectPort($server): ?int —
the redirect_uri port parsed at the announce, kept past the slot's done beat
(the C-MCP-4.c correlation leg reads it after the fact); cleared on re-arm and
in releaseAll. IN-STEP test flip: the announce-once stub's bare-URL-at-column-0
line gained leading whitespace — column-0 was never the OAuthLoopbackFlow shape
and the anchored law now matches the shape only; disclosed per plan §1.8.

## Leg 2: mcp_list exact-name lookup + names-only overview
New `tool` arg (type string, that schema only, required stays []): exact-name
match over the frozen dispatched roster, entry printed with its FULL
schemaLine; a miss is information, not a failure (same polarity as the
empty-server filter, stated in-code). Unfiltered/server-filtered listings whose
full schema text would overflow the cap answer names-only + a one-line
overview teaching the fetch door; the server filter survives the overview;
truncateOutput at DEFAULT_MAX_OUTPUT_BYTES remains the final guard on every
arm. description() extended (sentence floor intact); position 39, gloss, Read
class byte-untouched.

## Leg 3: SSE fork TLS retention
SseMcpServer tracks `socketOpenerPid` beside $socket (deliberately distinct
from $sessionPid, which is claimed BEFORE the establish legs and cannot answer
"who owns this fd" at replace time). openStream RETAINS into a
`retainedSockets` list before replacing a socket it did not open; closeStream
gates the same way (defense-in-depth, same root cause, same named method's
teardown twin). A TLS stream destructor runs SSL_shutdown — a close_notify on
the parent's still-live session — so the child must never fclose an inherited
handle; process exit is the kernel's job. Class FORK-SAFETY paragraph truth-
flipped in-step.

## Tests (fails-before-fix proven; mutations cp+md5-restored)
- testTheAuthorizeUrlIsAnnouncedIntactPastTheServerUrlLine: EXACT
  OAuthLoopbackFlow shape — backticked server-URL line, blank line, 2 KB
  authorize URL alone indented; asserts the announce is the authorize URL
  VERBATIM, never the server URL, and redirectPort === 41239 before AND after
  the done beat. Mutation M1 (announce restored to the old first-match-on-tail):
  exactly this test red; restored.
- SseMcpServerForkTlsTest (NEW): fork simulated over the class's own
  pidProvider seam (SseMcpServerTest's own convention — no pcntl). Plain
  socket-pair stub with the 200+text/event-stream head pre-buffered; the
  transport holds the SOLE client reference so a non-retaining replace runs
  its destructor immediately, and the fixture-side end is the EOF witness.
  Three pins: child replace leaves the inherited stream OPEN (M2: retain block
  removed → 1 red); the child still CLOSES its own replaced stream — retention
  is scoped, not a blanket leak; closeStream on an inherited handle retains
  too (M4 → 1 red). TLS close_notify is PLAUSIBLE under this plain-tcp stub,
  NOT observable — the docblock says so; the pin is the fclose, which is the
  proximate cause.
- testAnOverCapRosterAnswersNamesAndTheToolArgumentFetchesSchemas +
  testTheServerFilterSurvivesTheOverCapOverview: 24 fatSet descriptors overflow
  the cap → overview names EVERY tool, sheds every schema (no "note", no 'xxx'
  padding), teaches the door; `tool` fetch brings the one schema back and
  answers ONLY the named tool; server filter survives the overview. Mutation
  M3 (overview branch neutered → plain truncation): exactly these two red;
  restored (md5-verified).

## Gates (plain pipe, env -u LINES -u COLUMNS, cwd worktree candy-code)
- --filter 'McpLoginFork|McpDispatcherTools|SseMcpServer|ToolDocDrift|PromptStability|BuiltInTool'
  → 334 tests, 0 failures (consumers incl. BuiltInToolCorpus + ToolDocDrift:
  catalog position 39 unchanged, 39 built-ins stays).
- five-guard → ONLY the two staleness-pair arms red: pinned 25,537 vs live
  26,127 (pre-existing staleness at base + this lane's +6: SseMcpServerForkTls
  +3, McpLoginFork +1, McpDispatcherTools +2). suite-figure.json and
  durations.tsv UNTOUCHED per law; the gate owns the re-pin and the TSV rows.
- php -l clean on every touched file.

## Disclosed
- PathGlob src docblock census flip was NOT taken: the +1/+1 corpus move came
  from this lane's own URL literal carrying '?', respelled via chr(63) per the
  house GlobDialect law (the harvest admits every glob-shaped literal into
  BOTH corpora) — GlobDialect byte-identical, guard green, src/Util untouched.
- PromptStability: grep found NO mcp_list pins (externally-wired schemas) —
  nothing to move; the risk note resolved empty.
- The slot-0 leading-whitespace stub flip (leg 1) is the only existing-test
  edit; regression-reasoned, documented in-test.
- redirectPort is the minimal public surface C-MCP-4.c asked for; nothing
  consumes it yet BY DESIGN (wired, not deleted, at the next step).
- VIS-3-P1: COMPARISON.md grep 'launch-time entry bytes' = 0 (both trees), as
  expected; COMPARISON.md NOT edited (orchestrator-owned).
- docs/MCP.md: mcp_list bullet extended only; no numerals added.
