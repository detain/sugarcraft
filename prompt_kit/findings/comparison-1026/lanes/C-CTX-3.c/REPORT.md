# C-CTX-3.c — web instruction editing + `#` quick-add — LANDED

Branch `cc/C-CTX-3.c`, base `86f80fef8`, exactly ONE commit:
`c33e189af7697cfadd0ac724571a08260f00cb78` (cat-file-verified; %ae and %ce both
detain@interserver.net, zero Co-Authored lines, subject-prefixed). 10 files,
+1071/−4. Worktree clean at tip.

## What landed

**Server (the brief's enabling method).** `context.instructionWrite`
(write scope, side-effecting) in `ContextMethods.php` + schema in
`MethodSchemas.php`: exactly one of `content` (replace) / `append` else
`instruction_mode`; `expectBytes` conflict latch on both arms
(`instruction_changed`, -32009); the jail is the fresh
`context.sources` listing (`instruction_not_listed`); symlinked target/home
refused (`instruction_symlink`); result capped at the loader's
`MAX_DOCUMENT_BYTES` 61,440 (`instruction_too_large`); `personal` sentinel
resolves `~/.candy-code/AGENTS.md` server-side, created 0600 via
AtomicFileWriter (perms-preserving on rewrite); append rides
`file_put_contents(LOCK_EX)`; success clears the stat cache (fresh
`filesize` after lock-append — the stat-cache priming bug was real and is
pinned), announces `context.changed` reason `instructions`, answers
`{path, bytes, mode}`. ContainedPath docblock numerals + inventory row +
NUMBER_WORDS rode in-step (sixty-five/thirty-three).

**Web.** `ContextPanel.vue`: per-row Edit affordance gated on the
`write` scope; save spells the method with the listed `expectBytes`;
`instruction_changed` keeps the modal open, names the reason, reloads the
listing at once; personal row writes under `personal`. `Composer.vue`: the
web twin of the TUI quick-add — one-line `#` draft raises a confirm chip
(no turn); confirming (Enter/button) appends `- text\n` through the same
method; Escape/Cancel retires the chip with the draft untouched;
`##`/multi-line/bare-`#`/busy/attachment drafts never raise it.

**Docs.** `docs/MEMORY.md` rows for the served write channel and the web
mirror (guards re-derived green in-step).

## Tests / gates (all targeted; no full suites)

- PHP: `--filter ContextInstructionWriteTest` → 10T/42A OK (2 pre-existing
  fixture-family PHPUnit Deprecations, same pair ContextTrustPromptTest
  shows). Re-run at tip: OK.
- Docs guards: `--filter 'DocFigureProseDriftTest|MemoryDocumentationDriftTest|ServerProtocolDocumentationDriftTest'`
  → 106T/4212A OK.
- ContainedPath inventory in that window: the 2 failing arms are
  PRE-EXISTING BASE REDS at `86f80fef8` (foreign roster drift: AgentMemory,
  SkillLoader 3→4, hand-spelled LaunchContext/EnvironmentBlock/
  ContextHostCommand/MemoryCommand/TranscriptProjector rows, PathJail 5→6 —
  proven via `git show 86f80fef8:...`; welder domain, W20-cure pattern).
  My added row is consistent on both sides; the 65/33 numeral arms hold.
- Protocol trio: `gen-protocol-schema.php --write` + `npm run gen:protocol`
  regen-verified IN-LANE (schema + SERVER.md + generated.ts carried
  instructionWrite; schema/SERVER guards green with them; vue-tsc rc=0 with
  the regenerated generated.ts), then RESTORED — the welder regenerates
  (plan §1.8), so the tip's drift guards are intentionally red until weld.
- vitest SERIAL: `contextPanelEdit.spec.ts` (5) + `composerQuickAdd.spec.ts` (6)
  + regressions `contextPanel.spec.ts` + `composerSuggestion.spec.ts`
  → 4 files, 35T/35A passed, exit 0.
- Mutations: `quickAddLine`-neuter + `canWrite`-false → 9 red (only the
  negative-polarity legs stayed green, as they must); cp+md5 restore, 11/11
  green again.
- Hot-rule check vs parked `cc/C-MCP-2.c`: its Composer hunks (imports tail,
  popup state, search fn, onKeydown 541–597, suggest-lists template 640) are
  section-disjoint from every quick-add region (imports head, handler region
  before defineExpose, submit guard, Escape branch, dictation-notice +
  styles). Merge is context-adjacent only; welder re-gates the pair.
- `git status` at tip touches nothing forbidden: no suite-figure.json, no
  durations.tsv, no dist/, trio committed at base state.

## Disclosures

- Prior zombie session's uncommitted PHP work was taken over mid-flight
  (it stabilized ~01:00); all final content is gate-verified at this tip.
- The 31-byte expectations in the tests were measured with strlen, not
  manual count (first-pass manual counts were wrong; corrected in-test).
- Web store injects `idempotencyKey` on side-effecting requests; specs use
  `toMatchObject` + explicit `content`/`append` absence pins — server
  objects are open by default (`Schema::object` `$open=true`) so the key
  validates.
