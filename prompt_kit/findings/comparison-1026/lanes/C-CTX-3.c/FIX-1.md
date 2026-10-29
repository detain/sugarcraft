# C-CTX-3.c — fix round 1 (fixer report, 2026-10-29)

Base lane tip c33e189af → NEW tip **93c08ab87** (one commit, 5 files, +129/−12; %ae=%ce=detain@interserver.net separate greps, 0 Co-Authored trailers, no caliber). Worktree /home/sites/cc-cmp-C-CTX-3.c dirty=0 after commit.

## Finding → cure map

**MAJOR-1** — `ContextMethods.php` `personalWriteTarget()` minted an empty 0600
AGENTS.md during sentinel resolution, BEFORE the `expectBytes` latch
(`:197 $currentBytes = is_file($target) ? filesize : 0`). A refused panel save
of the `~/.claude/CLAUDE.md` stand-in row left the empty primary, which
`loadPersonal()` then prefers — masking the stand-in and evicting the
operator's personal instructions while landing nothing.
Cure: resolution no longer creates (`git show 93c08ab87`: eager-create block
removed; latch line kept exactly as prescribed — absent primary latches 0 →
stand-in save refuses `instruction_changed` and costs NOTHING); creation rides
the success path (`$isPersonal ? 0o600 : null` mode arm at :185-188 +
`AtomicFileWriter::write`, unchanged), so a first unlatched quick-add still
cannot fail on absence. Docblock rewritten to state the law.
Note: the aborted prior attempt had ADDED a `$latchedBytes` stand-in-row latch
making those saves succeed — beyond the finding's prescription and against
the conflict-latch-on-disk-truth law; it was reverted (final file contains
zero `latchedBytes`).
Pin: `ContextInstructionWriteTest::testARefusedSentinelSaveOnTheStandInRowMintsNothingAndKeepsTheStandIn`
— temp HOME, stand-in `.claude/CLAUDE.md` (31 B) listed as tier `personal`;
sentinel save with `expectBytes=31` refuses −32009/`instruction_changed`;
asserts AGENTS.md still absent, stand-in bytes intact, re-list shows the same
stand-in path/bytes (stand-in still the source); then an unlatched append
success mints the primary 0600 (deferred creation proven).

**MINOR-2** — `MethodSchemas.php` unused `InstructionFileLoader` import.
Cure (use-it option): both `Schema::string(61440)` caps (:524-525) now spell
`InstructionFileLoader::MAX_DOCUMENT_BYTES` — one ceiling shared with the
runtime `instruction_too_large` check (:210-212). No 61440 literal remains
anywhere in candy-code src/ or tests/ (grep 0).

**MINOR-3** — `Composer.vue` `answerQuickAdd()` confirmed the stale chip-open
offer and cleared an edited draft. Cure: the `quickAdd.value !== null` arm
re-derives `quickAddLine(props.modelValue)` first — edited `#` draft appends
the EDITED line; a draft that left the `#` shape calls `cancelQuickAdd()` and
returns false so the Enter sends as a plain prompt (the block doc's
"re-evaluates the shape" promise); Esc-retire untouched.
Pins: 2 new vitest cases in `composerQuickAdd.spec.ts` (edited draft appends
'- second, better thought\n'; '## prose heading now' retires chip, 0
instructionWrite, send emitted).

## Gates (exact, cwd=candy-code / candy-code-web, linked worktree)

- `ContextInstructionWrite` filter: **11 T / 54 A** green (10→11, +new pin).
- `ContextMethods|ContextPanel|ContextInstruction`: **12 T / 60 A** green.
- `tests/Protocol` dir: **156 T / 1305 A / 1 F** = PRE-EXISTING trio staleness
  (`ProtocolSchemaDriftTest` — committed schema lacks the lane's
  context.instructionWrite block; weld regenerates).
- `ContainedPathInventory|ReadPathCensus`: **68 T / 760 A / 3 F** — all
  foreign base-reds per mission (2 inventory arms name Host/Commands rows;
  census diff shows ONLY `Chat.php|file_get_contents 2→3`; ContextMethods
  adds no sink).
- vitest `--no-file-parallelism`: composerQuickAdd **8/8**; panels
  contextPanel+contextPanelEdit **24/24**; composer neighbors (dictation,
  documents, dropAndChips, filesImages, history, suggestion) **56/56**.

## Trio law (§1.8)

In-lane regen-verify-restore executed: `gen-protocol-schema.php --write` then
grep `"maxLength": 61440` → 2 (constant swap is byte-identical in schema
output), `git checkout -- docs/protocol/candy-code.v1.schema.json
docs/SERVER.md`; commit contains ZERO trio/suite-figure/durations paths
(`git show --name-only | grep -cE 'schema.json|SERVER.md|generated.ts|suite-figure|durations'` → 0); final `git status --short` empty.

## Mutation proofs

- **M1**: restored the eager-create block (python anchor, md5-fenced cp
  restore `/tmp/opencode/C-CTX-3c-fix1/ContextMethods.tip` 94abd5b1…) → pin
  fails EXACTLY 1F on `the refusal minted no primary`; restore → green 12 A.
- **M2**: stripped the re-derivation hunk → composerQuickAdd fails EXACTLY the
  2 new pins (6 old pass); md5-clean restore (ba79b5fe…).
- **M3**: structural (no phpunit red is expected — byte-identical schema):
  import occurrences base **1** (the `use` line itself = unused) → working
  **3** (import + 2 uses); the global php-cs-fixer is ≤8.1-era and cannot
  discriminate under PHP 8.3.6 (ran --dry-run --rules=no_unused_imports,
  checked 0 files both ways — recorded, not relied upon).

## Harness hazard observed (report to lead)

During recon a transient second writer touched ContextMethods.php in this
worktree (an eager-create variant appeared and vanished mid-window; the staged
index additionally carried the prior attempt's over-reach latch, which was
`git reset` + re-verified before commit). No competing process survived;
final commit is md5-fence-verified against the gate-green tree. Possible
zombie of the lane's prior incarnation — recommend the lead confirm no twin
session holds this worktree before weld.

Tip: 93c08ab87 on cc/C-CTX-3.c (base c33e189af). Twin-session note: the change
set was committed as 93c08ab87 while this fix session's own commit ran —
`git commit` reported "nothing to commit, working tree clean" because the
identical content was already in. Content independently re-verified by this
session against that tip (author/committer %ae and %ce = detain@interserver.net
via SEPARATE greps, 0 Co-Authored trailers, 5 files +129/-12, tree clean, all
gates above re-run on the committed disk state). No second commit minted;
round-1 work rides the one commit.
