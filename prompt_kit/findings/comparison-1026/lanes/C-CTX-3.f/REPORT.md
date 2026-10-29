# C-CTX-3.f — Standing intents (intent memory type with when: trigger)

## Landed (this commit, files: 4 src + 1 new test + 3 docs)
- `src/Memory/MemoryEntry.php` — `when:` field: trailing ctor param (defaulted, named-arg
  callers unaffected), `normalizeWhen()` (canonical `path:<glob>` / `keyword:<w>,<w>`; bare
  glob-metachar or leading-`/` value routes to path:, else keyword; refuses blank,
  `path:` without payload, `keyword:` without words, and unwired `tool:`/`glob:`/`regex:`
  prefixes LOUD with the remedy spelled); `when()/whenPathGlob()/whenKeywords()/withWhen()`;
  every `with*()` and `with('when',…)` and `toArray()` carry it.
- `src/Memory/MemoryWriter.php` — `TYPES` += `intent`; `save(…, ?string $when = null)`:
  intent without a trigger refused, trigger on a non-intent refused, trigger normalised
  BEFORE any write; `restyle()` (retype folded in) applies type+when post-route via
  get/withType/withWhen/update; `intentNotes()` lists armed intents (repo-first, id-dedupe,
  only type intent && when !== '').
- `src/Context/RulePathNudge.php` — intent seam inside the tracker: `bindIntentSource()`
  (+`intentSourceBound()` introspection pin), re-walk per consult mirroring the rule
  loader (audit 15d-20), own `<system-reminder>` envelope riding AFTER the rule envelope
  and spending only the LEFTOVER of the same caller budget (class ceiling stays true),
  shared announce ledger key-namespaced `intent:<id>`, digest = when+body so an edited
  intent re-announces, MAX_ENTRIES(2)/deferred-note mirror, no pointer fallback (a note
  has no follow-me path), every byte `PromptFence::escape()`d. Rule envelopes are
  byte-identical when no intents are bound (RulePathNudgeTest green unchanged).
- `src/Tools/BuiltIn/MemoryTool.php` — production BIND: `fromCatalog()` hands
  `static fn(): array => $writer->intentNotes()` to the tracker (the catalog build is the
  one place ToolBuildContext carries memory+ruleNudge; LaunchContext NOT touched;
  withWriter delegation copies deliberately do not re-bind). Save schema `when` property +
  string-validation row (malformed → `Error: when …` shape); save reply appends the
  standing-intent sentence ONLY on a truth-not-promise read-back (armed iff the located
  entry's when is non-empty — inert until the store hunk below lands); index() lists armed
  intents under their own `## standing intents` heading FIRST with trigger + store label,
  groups byte-identical otherwise; indexLine()/header() gained `when` legs (empty when →
  byte-identical); description() documents the vocabulary.
- `tests/Context/StandingIntentTest.php` — 18T/76A: normalizeWhen accepts/refuses, entry
  carry-through, path intent fires once on matching path + silent elsewhere + still pending
  on fresh tracker, keyword whole-word (deploy vs deployx) once, edited-body re-announce,
  forgery containment (one opener, escaped body), 3-intent overflow → 2 + deferred note +
  third fires next consult, budget too small → null marks nothing, writer refusals, tool
  schema-error shapes, well-formed save, listing legs, fromCatalog bind reaches
  production (bound tracker answers; unbound control stays unbound), project file lands
  `repo/.candy-code/memory/project/<id>.md`, delete-by-id. Fixtures obey §1.9: globs built
  via `chr(42)` star() — GlobDialect corpus unchanged (guard green in-lane).
- `docs/MEMORY.md` — roster sentence now five types in word/order (DocFigure arm is a
  live-derive pin), Memory-tool table save row lists `intent`+`when`, new "#### Standing
  intents" section INCLUDING a status paragraph that `when:` does not survive the store
  write until the seam below lands (feature inert-but-honest today).
- `docs/PROMPT_ENGINEERING.md` ×2 passages + `docs/SKILLS.md` ×1 — "`IntentTrigger` has no
  consumer in `src/`" (was Intent+Keyword have); KeywordTrigger's new consumer named as the
  RulePathNudge standing-intent seam, rule `keywords:` still truthfully not applied.
  (All three flips are DocFigureProseDriftTest-derived and land IN-STEP per §1.8.)

## Gates (targeted, cwd=candy-code -c phpunit.xml, plain pipe)
- StandingIntentTest 18T/76A OK; Context+Memory dirs 1648T — ONLY foreign reds.
- `--filter 'Nudge|Rule'` full tests/ 898T green (rule-envelope byte-stability).
- Guards: ToolDocDrift|GlobDialect|DocFigureProseDrift|MemoryDocumentationDrift 119T green;
  `gen-tool-docs.php --check` + `gen-command-docs.php --check` up to date (gloss unchanged;
  README tools:roster needs NO union from this lane).
- MemoryTool/MemoryStore/MemoryWriter/MemoryEntry/MemoryCommand neighborhood 269T — reds
  only the foreign MemoryPromptWiringTest family (its method names contain "MemoryStore",
  hence the filter grab).

## Foreign standing reds proven NOT this lane (file-revert probe at base)
- MemoryPromptWiringTest 6E/1F/1W + EnvironmentBlockTest 4E — same
  InstructionDiffTracker:337 null-$seen family, logged foreign at this base by the
  batch-12 weld commit itself.
- DreamPassTest:288 — logged standing.
- ToolCapSettingsTest 2F (webSearchBackend roster + RiskClass Tuning→Egress) — RE-PROVEN
  identical with every C-CTX-3.f file reverted to base; weld log did not name them; they
  belong to a foreign settings pick, not chased.

## WELDER SEAMS (loud, actionable)
1. **MemoryStore `when:` persistence (~3 lines, UNDECLARED file).** Today decode()/writeEntry()
   drop the field, so an intent's trigger does not survive ANY store write; `intentNotes()`
   filters `when !== ''` so nothing falsely arms (inert-but-honest; MEMORY.md says so).
   Proposed hunk in `src/Memory/MemoryStore.php`:
   - `writeEntry()` frontmatter array: after `'scope' => …` insert
     `...($entry->when() !== '' ? ['when' => $entry->when()] : []),`
     (keep plain notes' bytes untouched).
   - `decode()` return: add named arg
     `when: isset($meta['when']) && is_string($meta['when']) ? $meta['when'] : '',`
     and wrap: a hand-edited malformed trigger must not unread the whole note —
     `try { … } catch (\InvalidArgumentException) { when '' }` (design call: refuse-armed vs
     tolerate; refuse-armed keeps the save door loud, the read door lenient).
   - After this lands: re-run StandingIntentTest (all 18 stay green — the truth-not-promise
     save sentence flips ON automatically) and MINT the e2e pin: save intent through the
     tool → fresh store decode → tracker fires (one test method worth adding at weld).
2. **`/memory list` user-side own heading** (`src/Host/Commands/MemoryCommand.php`,
   UNDECLARED — C-CTX-3.e sibling also touches this file). Add an intents section mirroring
   the tool index (heading + `when:` + id + delete-by-id stays working). **Lang law:**
   `ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost` reds on unused
   `host.*` keys, so the key and its MemoryCommand caller MUST land in ONE commit. Proposed:
   `'host.memory.intents.heading' => '**Standing intents (each fires once when a tool touches its when: trigger):**'`
   (+ `.none` row if the list should say so).
3. **DUR row:** new file `candy-code/tests/Context/StandingIntentTest.php` (18 tests) —
   durations.tsv row mint owed at weld (file runs ~0.12s).
4. **Suite figure:** +18T / +76A live-enum drift for the re-pin pair (lane forbidden).
5. **C-CTX-3.e rebase collision:** both edit `docs/MEMORY.md` (mine: roster sentence :170,
   Memory-tool table, new section before "### The three tiers"; theirs: import/export
   regions) and both land in Host/Commands memory listing — lead merges with both kept;
   the five-type roster sentence is DocFigure-pinned (word+order), keep `intent` LAST.

## Design decisions recorded
- `tool:` triggers are REFUSED at save with the remedy spelled (plan vocabulary listed
  tool-name; the only live seam matches paths — silently-inert was rejected per the
  no-dormant-code/no-fabricated-success laws).
- Keyword intents whole-word match the touched PATH text (the seam hands paths, not
  prompts); stated in schema description, MEMORY.md, and test names.
- Bind site is `MemoryTool::fromCatalog` (declared file) instead of LaunchContext (not
  declared, not a hub): catalog build is where memory+ruleNudge meet; one-shot per launch;
  delegations steer from the session store by design.
