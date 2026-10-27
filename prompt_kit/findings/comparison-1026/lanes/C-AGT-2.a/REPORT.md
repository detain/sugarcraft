# C-AGT-2.a — `/review` refuses refs the scanner cannot attach

**Verdict: DONE** — commit `8a5693d14e10f1bf390bf380c577e09a839f6971` on `cc/C-AGT-2.a` (base master ff10efc43), worktree `/home/sites/cc-cmp-C-AGT-2.a`. Author+committer Joe Huss <detain@interserver.net> (separate `%ae`/`%ce` greps = 1/1), zero Co-Authored trailers, tree clean after explicit-path commit, never pushed.

## Item
C-AGT-2 (source GIT-1-P1, MAJOR). `isDiffRef()` checked only `REF_PATTERN`, while the
mention scanner trims trailing punctuation and `diff()` runs `diff-index <ref>` (ONE
tree-ish). `base..HEAD`, `HEAD@{1` and `@{u}` passed validation, so Chat submitted a
review turn whose diff never attached — a billed turn reviewing nothing.

## Changes (7 files, +276/−24)
- `src/Attachments/ContextMentions.php` — `trimmed()` is now a char-walk that keeps a
  trailing `}` while the head holds an unmatched `@{` (reflog addresses survive the
  trim); `isDiffRef()` is the scanner's full round trip: refuses empty, `..` ranges,
  dangling `@{` opens (which no longer cancel at the trim), then requires
  `trimmed('diff:'.$ref) === 'diff:'.$ref`.
- `src/Commands/ReviewCommand.php` — new `refError(string $ref, string $root): ?string`:
  pattern/round-trip gate first, then a bounded pre-resolution via
  `GitRunner::new($root)->withInheritedGitEnv()->withTimeout(ContextMentions::DIFF_TIMEOUT_SECONDS)->capture(4096,'rev-parse','--verify',$ref.'^{tree}')`;
  timeout or non-zero exit returns the bad-ref row. Abstains (null) when git is
  unavailable — that world stays governed by the scanner's own notice. Class docblock
  names `refError()`.
- `src/Chat.php` — **only the declared `[chat:reviewTurn]` region**: the refusal branch
  now calls `ReviewCommand::refError()` and answers with its row before submitting;
  docblock reworded to match.
- `lang/en.php` — `cmd.review.bad-ref` drops the false `main..HEAD` example (states
  single tree-ish; ranges not yet supported); `cmd.review.hint` → `[<tree-ish>]`.
  `cmd.review.description` untouched.
- `docs/COMMANDS.md` — only the `/review` row's Takes cell `[<ref>]` → `[<tree-ish>]`
  (see deviations).
- `tests/Chat/ReviewCommandTest.php` — range arm flipped to "no turn + bad-ref row";
  new arms: `HEAD@{1` attaches (two-commit temp repo, assert on the submitted row's
  attachment path AND diff bytes), `@{u}` attaches in a temp repo with a real pushed
  upstream, `@{u}` refused pre-submission in an upstream-less repo, `isDiffRef`
  round-trip table (HEAD/sha/reflog/upstream true; `,`/`HEAD@{1`/`main..HEAD`/empty/
  `-x`/spaces false).
- `tests/Attachments/ContextMentionsTest.php` — `@diff:HEAD@{1}` resolves and attaches
  in a temp repo (with `,`-suffix notice polarity + dangling-open refusal arm).

## Tests (targeted, plain pipe, cwd=worktree/candy-code)
- `--filter 'ReviewCommandTest|ContextMentionsTest'` **before fix**: 23T/133A/6F (the six
  new/changed arms — regression-before-fix proven). **After fix**: `Tests: 23,
  Assertions: 149, PHPUnit Deprecations: 2` OK (2 deprecations pre-existing at baseline).
- Guards: `--filter 'CommandsTableTakesColumnDriftTest|CommandDocsDriftTest|RegistryTranslationTest|DocFigureProseDriftTest'`
  → `Tests: 108, Assertions: 5015` OK (same baseline deprecation pair). COMMANDS.md row,
  registry hint and lang changes moved in the same commit (§1.8).

## New test files
None. `suite-figure.json`, `durations.tsv`, `dist/`, protocol files untouched.

## Deviations / notes
1. **docs/COMMANDS.md hand-row despite brief's "docs: none"** — the hint change moves the
   live registry's Takes cell, and `CommandsTableTakesColumnDriftTest` re-derives that
   generated-region row from the registry; §1.8 (docs move in the same commit) forced the
   one-cell edit. The weld's regeneration is byte-identical (guard green on it).
2. **Unmatched-`@{` guard added beyond the brief's literal gate list** — `trimmed()`'s
   brace-keep cannot cancel a dangling open (`HEAD@{1` round-trips clean), so `isDiffRef`
   refuses unbalanced opens explicitly; without it the turn would still bill against a
   non-attaching mention (the scanner's `@{` token rule refuses it).
3. `refError()` returns the fully-formatted `Lang::t` row so `reviewTurn` stays
   formatting-free and Chat no longer names the key itself.
4. **Seam (left unowned, out of prefix scope) — reworded in fix round 2:** the
   validation is SHARED, not sibling-local: `/review` and `/security-review` both
   dispatch through the same `Chat::reviewTurn()` (:11762/:11773), so the sibling
   command NOW RUNS `refError()`'s gates too — this step's claim covers it. What
   remains is wording only: `cmd.security-review.hint` still reads `[<ref>]` and
   COMMANDS.md:389 carries the matching row (guard-consistent); suggest a
   follow-up step or weld note to move the sibling hint to `[<tree-ish>]` with
   its doc row.
5. Ranges (`base..HEAD`) are refused by design until C-AGT-6.b lands; docs now say so.

## Fix round 2 (review round 1: 0C/0M/2 MINOR step-local/3 NIT)

Fix commit `6da65595fc79714cc9f01282f6394ca658a03107` on `cc/C-AGT-2.a` (new tip;
2 files, +22/−2). Author+committer detain@interserver.net (separate `%ae`/`%ce`
greps 1/1), zero trailers, tree clean, never pushed.

1. [MINOR sl] REPORT note 4 (above) reworded: validation is SHARED via
   `reviewTurn()`, the residual seam is the `[<ref>]` hint + COMMANDS.md:389 row
   wording only. No code change (reviewer concurred "code needs nothing").
2. [MINOR sl] `ReviewCommandTest`: house `requireGit()` (byte-identical body+
   signature to `ContextMentionsTest::requireGit` — identical is the
   DuplicatedTestHelperDrift non-drift shape; NOT rostered in SuiteSkipRoster,
   matching every existing git-gated family — rostering would trip the
   "ON THE ROSTER BUT RAN WITHOUT SKIPPING" arm on normal runners). Placed at the
   top of each temp-repo builder, covering all git-dependent arms (final tree:
   three builder tests; the review's "four" counted the pre-gate range arm, which
   refuses at gate 1 without git). PROVEN red-then-green on a real git-less
   simulation (`PATH=/tmp/opencode/cc-cmp-C-AGT-2a/nogit`, locateOnPath is a PATH
   stat walk): before = 3 failures `sh: 1: git: not found`/127; after =
   `Skipped: 3`, 0 failures.
3. [NIT] `refError()` takes the comment option the finding sanctioned: the
   exitCode-124/timedOut branch now carries why it answers with the bad-ref row
   (unverified fails the "may this ref carry a billed turn" gate exactly like
   unresolvable — fail-closed kept; a new `cmd.review.*` key would mint a
   refusal shape no non-wall-clock test can reach). Behavior byte-unchanged.
4. [NIT] `ReviewCommand.php` class docblock (and the same stale spelling in the
   `prompt()` docblock): `/review [<tree-ish>]`, security sibling kept at its
   current `[<ref>]`.

Gates (cwd=worktree/candy-code, plain pipe, `env -u LINES -u COLUMNS`):
`--filter 'ReviewCommandTest|ContextMentionsTest'` 23T/149A OK (matches the
round-1 figure — no test methods added); guards
`DuplicatedTestHelperDrift|GlobDialect|ChildWallClockBudget|CommandsTableTakesColumnDrift|CommandDocsDrift|RegistryTranslation` 55T/12516A OK +
`DocFigureProseDrift` 91T/3889A OK (2 deprecations pre-existing); the five-guard
filter additionally shows ONLY the inherited ReadmeSuiteFigureDrift staleness pair
(25,537 pinned vs 25,541 live — program figure drift, weld re-pin duty; this
round added zero test methods). No suite-figure/durations/protocol/dist touches.
