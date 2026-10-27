# C-SEC-3.b — lane report (VERDICT FIRST)

**Verdict: PARTIAL-DELIVERED.** Foreign-row ruling applied in full (docs +
docblocks + test hedges). The per-origin opt-in half is a **file-list scope
gap** — it cannot land inside the step's ownership, and minting the two keys
without a consulted path would be a fabricated success path. Welder must
carry the opt-in into the identity-stamp steps.

**ORCHESTRATOR CORRECTION (phantom commit):** the resume directive claimed
branch `cc/C-SEC-3.b` carried `636490446` "two audited user-tier shell
allow-lists…", uncommitted LayeredSettings hunks, and a `.cbcheck.php`
scratch. Disk said otherwise: the object exists nowhere in the shared repo
(`git show` fails, common-dir = /home/sites/sugarcraft/.git), the tree was
pristine at base ffe7b910d, no scratch file. Nothing was folded or reverted;
no opt-in code ever landed. This lane's work is the one commit below.

## Commits (re-read from git log)
- `acb746a59` C-SEC-3.b: apply the foreign-row ruling; opt-in deferred to the
  row-identity steps — base `ffe7b910d`, branch tip, clean tree.
  Author/committer: Joe Huss detain@interserver.net (%ae and %ce grepped
  separately), zero trailers, --no-verify (no caliber hook in this era).
  numstat: docs/COMMANDS.md +1/−1 · src/Commands/CommandShellPosture.php
  +8/−6 · src/Host/TurnController.php +7/−5 (doc-only hunk in the refusal
  docblock) · tests/Commands/CommandShellPostureTest.php +3/−3 ·
  tests/Host/TurnControllerBundleShellTest.php +1/−1. Total +20/−16.

## What landed (ruling half, per owner ruling verbatim)
- COMMANDS.md tier table foreign row: "never — imports run no shell" → "the
  tier decides: user **yes**, project **only if this root is trusted**".
- CommandShellPosture item-3 docblock: "C-SEC-3.b gives the imported trees
  their own posture; until then…" → settled-permanent answer + opt-in belongs
  to the two third-party arms only, landing with the row-identity stamps.
- TurnController refuseCommandShell docblock: same re-attribution; "today's"
  → "settled".
- Both "C-SEC-3.b will rule" test hedges → settled wording.

## Deferred (scope gap — STOP+REPORT per builder rule, files not edited)
Faithful opt-in needs, all OUTSIDE the design block's files.create/modify:
1. `CommandSpec.php` — no origin-name field (claimed by C-EXT-2.e).
2. `ForeignCommandDiscovery.php` — bundleDirs() drops the bundle name (claimed
   by C-EXT-4.c).
3. `McpPromptCommands.php` — fetched spec drops the server name; matching only
   the sanitized name prefix is lossy (two servers can collide → grant
   widening). Claimed by C-MCP-2.a.
4. Caller plumbing — TurnController is stateless; the lists must arrive via
   Chat.php (owner order: do not touch) + SessionHost.php (not in file list).
Optional trailing params with `[]` defaults would make keys no reachable code
consults — refused as §1.12 fabrication. Therefore also NOT landed (would
have been): PermissionSettings definitions, LayeredSettings roster blocks,
lang/en.php settings.* keys, SETTINGS.md regen, README :1271 user-tier-only
sentence + word number, SettingsWriter::trustKeys() classing, opt-in test
arms. TrustKeys/guards report: had they landed, TrustKeyDocumentationDriftTest
arm 1's regex `(trustedProject[A-Za-z0-9_]+)` does NOT capture
`trustedBundleCommands`/`mcpPromptShell` (camelCase, no trustedProject prefix —
MAX_OUTPUT_TOKENS-class deviation, no PERMISSIONS.md churn), and arm 2 would
demand +2 backticked names + word bump seventy-three→seventy-five.
**Recommendation:** welder should re-cut the opt-in as a follow-up lane owning
{CommandSpec, ForeignCommandDiscovery, McpPromptCommands/CommandLoader,
TurnController, Chat/SessionHost call sites, PermissionSettings,
LayeredSettings, lang, SETTINGS.md, README} together — or append it to
C-EXT-4.c, which already claims the discovery half.

## Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'CommandShellPosture|TurnControllerBundle|PermissionSettings|SettingsWriter'`
  → OK 135T/4861A (2 pre-existing PHPUnit deprecations).
- `php tools/gen-settings-doc.php --check` → rc 0 "up to date" (note: the tool
  lives at candy-code/tools/, not root tools/ as §2 spells it).
- `--filter 'TrustKeyDocumentationDrift|DocFigureProseDrift'` → OK 103T/4430A.
- five-guard → 133T, ONLY failures = ReadmeSuiteFigureDrift staleness pair
  (25,537 pinned vs 25,827 live — inherited from base; +0T this lane).
- php -l clean on all four touched PHP files.

## Deviations
1. No opt-in code/keys/tests (scope gap above) — the step's `tests` line
   ("opt-in arms", "opted-in bundle") not fulfillable in-list.
2. Commit is one doc-truth increment; §1.3 one-step-one-commit honored, but
   the step as planned spanned two waves.
3. ShellProvenance.php:19 still reads "C-SEC-3.b is the planned per-origin
   opt-in" — remains TRUE (planned, ruled), file not in list; no seam needed.
